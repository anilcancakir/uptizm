<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Validates a status page brand-logo upload.
 *
 * The file is rendered by a PUBLIC page for anyone holding the slug, which is
 * what shapes every rule here:
 *
 * - No SVG. An SVG is a script container, and the one surface this image reaches
 *   is a page served to strangers. {@see StatusPage::LOGO_EXTENSIONS} is the
 *   single definition of the allowed set; the stored extension comes from the
 *   same list, so a type that passes here is a type the serving route can name a
 *   `Content-Type` for.
 * - A dimension ceiling, not only a byte ceiling. A 200 KB PNG can still be
 *   20000 x 20000 pixels, which is a decompression bomb for the preview renderer
 *   rather than for the upload itself.
 * - A brand mark is small on every surface that shows it (a 40pt square in the
 *   editor, a header mark on the public page), so 512 KB is generous rather than
 *   tight, and keeps one page's logo well inside a single preview render.
 */
class UpdateStatusPageLogoRequest extends FormRequest
{
    /**
     * Largest accepted upload, in kilobytes.
     */
    public const MAX_KILOBYTES = 512;

    /**
     * Largest accepted edge, in pixels.
     */
    public const MAX_EDGE_PIXELS = 2048;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                File::image()
                    ->extensions(StatusPage::LOGO_EXTENSIONS)
                    ->max(self::MAX_KILOBYTES),
                Rule::dimensions()
                    ->maxWidth(self::MAX_EDGE_PIXELS)
                    ->maxHeight(self::MAX_EDGE_PIXELS),
            ],
        ];
    }
}
