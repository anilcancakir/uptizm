<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationChannelRequest;
use App\Http\Requests\UpdateNotificationChannelRequest;
use App\Http\Resources\NotificationChannelResource;
use App\Models\NotificationChannel;
use App\Notifications\Channels\PagerDutyChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\Notification;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Team-scoped CRUD for {@see NotificationChannel} plus a live test-send.
 *
 * Mirrors {@see EscalationPolicyController}'s team-scope + 404-mask pattern
 * (cross-team access is masked as 404, never 403, so the existence of another
 * team's channels never leaks). The {@see NotificationChannelResource} masks
 * every stored credential, so no token/secret/url ever travels back in a
 * response. The `test` action actually drives the channel's send path and
 * reports the honest downstream outcome (a Slack `{ok:false}` or a webhook
 * non-2xx is surfaced as a failed test, not a false 200 success).
 */
class NotificationChannelController extends Controller
{
    /**
     * List the current team's notification channels, newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $channels = NotificationChannel::query()
            ->where('team_id', $request->user()->current_team_id)
            ->orderByDesc('created_at')
            ->paginate();

        return NotificationChannelResource::collection($channels);
    }

    /**
     * Create a notification channel for the current team.
     */
    public function store(StoreNotificationChannelRequest $request): JsonResponse
    {
        $channel = NotificationChannel::create([
            ...$request->validated(),
            'team_id' => $request->user()->current_team_id,
        ]);

        return NotificationChannelResource::make($channel)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a channel owned by the current team.
     */
    public function show(Request $request, NotificationChannel $channel): NotificationChannelResource
    {
        $this->authorizeTeam($request, $channel);

        return NotificationChannelResource::make($channel);
    }

    /**
     * Update a channel owned by the current team.
     */
    public function update(
        UpdateNotificationChannelRequest $request,
        NotificationChannel $channel,
    ): NotificationChannelResource {
        $this->authorizeTeam($request, $channel);

        $channel->update($request->validated());

        return NotificationChannelResource::make($channel->refresh());
    }

    /**
     * Delete a channel owned by the current team.
     */
    public function destroy(Request $request, NotificationChannel $channel): Response
    {
        $this->authorizeTeam($request, $channel);

        $channel->delete();

        return response()->noContent();
    }

    /**
     * Send a test notification through the channel and report the honest result.
     *
     * The send runs synchronously through the real channel path (Slack /
     * webhook), so the response reflects the actual downstream outcome rather
     * than an optimistic "queued" success. A delivery failure returns 502 with
     * `delivered: false`; a success returns 200 with `delivered: true`.
     */
    public function test(Request $request, NotificationChannel $channel): JsonResponse
    {
        $this->authorizeTeam($request, $channel);

        $delivered = $this->attemptTestDelivery($channel);

        return response()->json(
            ['data' => ['delivered' => $delivered]],
            $delivered ? HttpResponse::HTTP_OK : HttpResponse::HTTP_BAD_GATEWAY,
        );
    }

    /**
     * Drive the channel's send path once and report whether it delivered.
     *
     * The Slack/webhook channels report (not throw) a logical failure, so a
     * naive send would always look successful. To read the true outcome the
     * exception handler is temporarily swapped for a recorder that captures any
     * reported throwable during the synchronous {@see NotificationChannel::notifyNow()},
     * and a propagating transport error is caught as a failure too. The channel
     * is untouched: the honesty lives entirely in this observation.
     */
    protected function attemptTestDelivery(NotificationChannel $channel): bool
    {
        $original = app(ExceptionHandler::class);
        $recorder = $this->reportRecorder($original);

        app()->instance(ExceptionHandler::class, $recorder);

        try {
            $channel->notifyNow($this->testNotification());
        } catch (Throwable) {
            return false;
        } finally {
            app()->instance(ExceptionHandler::class, $original);
        }

        return $recorder->reported === [];
    }

    /**
     * Build the throwaway "test" notification, branching to the matching custom
     * channel class for the channel's type. The payload is a distinct
     * diagnostic ping so a real recipient can tell it apart from an incident.
     */
    protected function testNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<int, string>
             */
            public function via(object $notifiable): array
            {
                return match ($notifiable->channel_type) {
                    NotificationChannelType::Slack => [SlackChannel::class],
                    NotificationChannelType::Webhook => [WebhookChannel::class],
                    NotificationChannelType::PagerDuty => [PagerDutyChannel::class],
                    NotificationChannelType::Teams => [TeamsChannel::class],
                };
            }

            /**
             * @return array<string, mixed>
             */
            public function toSlack(object $notifiable): array
            {
                return [
                    'text' => 'Uptizm test notification: this channel is connected.',
                ];
            }

            /**
             * @return array<string, mixed>
             */
            public function toWebhook(object $notifiable): array
            {
                return [
                    'event' => 'test',
                    'message' => 'Uptizm test notification: this channel is connected.',
                ];
            }

            /**
             * @return array<string, mixed>
             */
            public function toPagerDuty(object $notifiable): array
            {
                return [
                    'event_action' => 'trigger',
                    'dedup_key' => 'uptizm-test-'.$notifiable->id,
                    'payload' => [
                        'summary' => 'Uptizm test notification: this channel is connected.',
                        'source' => 'uptizm',
                        'severity' => 'info',
                    ],
                ];
            }

            /**
             * @return array<string, mixed>
             */
            public function toTeams(object $notifiable): array
            {
                return [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.2',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'text' => 'Uptizm test notification: this channel is connected.',
                            'wrap' => true,
                        ],
                    ],
                    'actions' => [],
                ];
            }
        };
    }

    /**
     * Build an exception handler that records reported throwables while
     * delegating every other responsibility to the real handler.
     */
    protected function reportRecorder(ExceptionHandler $inner): ExceptionHandler
    {
        return new class($inner) implements ExceptionHandler
        {
            /**
             * @var list<Throwable>
             */
            public array $reported = [];

            public function __construct(
                protected ExceptionHandler $inner,
            ) {}

            public function report(Throwable $e): void
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e): bool
            {
                return $this->inner->shouldReport($e);
            }

            public function render($request, Throwable $e): HttpResponse
            {
                return $this->inner->render($request, $e);
            }

            public function renderForConsole($output, Throwable $e): void
            {
                $this->inner->renderForConsole($output, $e);
            }
        };
    }

    /**
     * Guard team ownership, masking a foreign channel as 404.
     *
     * A 403 would confirm the channel exists; the 404 mask keeps the existence
     * of another team's channels hidden.
     */
    protected function authorizeTeam(Request $request, NotificationChannel $channel): void
    {
        abort_if(
            $channel->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
