<?php

namespace Tests\Unit\Support\Proxy;

use App\Support\Proxy\ParsedProxy;
use App\Support\Proxy\ProxyListParser;
use Tests\TestCase;

/**
 * Pins the parser's silent-drop contract against the reference Go
 * implementation's own test suite (package-booster/internal/proxysrc,
 * read-only design reference, not a dependency).
 *
 * Every malformed-input case here asserts an EMPTY result and NO exception,
 * because the parser's whole job is to let one bad line pass without
 * blocking the refresh of every other exit in the list. The one case that
 * pins the opposite direction (a technically weird but legal line) is the
 * `@`/`/`/`#` password: this parser never builds a URL, so those characters
 * are ordinary bytes in the credential field and must survive untouched.
 */
class ProxyListParserTest extends TestCase
{
    /**
     * A single well-formed `host:port:username:password` line parses into
     * one ParsedProxy carrying all four fields verbatim.
     */
    public function test_parses_a_valid_line(): void
    {
        $result = ProxyListParser::parse("1.1.1.1:8080:user1:pass1\n");

        $this->assertCount(1, $result, 'A well-formed line must yield exactly one proxy.');
        $this->assertEquals(
            new ParsedProxy(host: '1.1.1.1', port: 8080, username: 'user1', password: 'pass1'),
            $result[0],
            'The parsed fields must match the input exactly.',
        );
    }

    /**
     * A line starting with `#` is a comment and must be dropped without
     * being mistaken for a malformed proxy line.
     */
    public function test_drops_a_hash_comment_line(): void
    {
        $result = ProxyListParser::parse("# this is a comment\n1.1.1.1:8080:u:p\n");

        $this->assertCount(1, $result, 'The comment line must be dropped, leaving only the valid line.');
        $this->assertSame('1.1.1.1', $result[0]->host);
    }

    /**
     * A blank line between two valid lines must be skipped, not counted as
     * a malformed row and not terminating the scan.
     */
    public function test_drops_a_blank_line(): void
    {
        $result = ProxyListParser::parse("1.1.1.1:8080:u1:p1\n\n2.2.2.2:9090:u2:p2\n");

        $this->assertCount(2, $result, 'The blank line must be skipped, not dropped along with a following row.');
    }

    /**
     * Windows-encoded (CRLF) lines must be trimmed before the regex runs;
     * a stray `\r` left at the end of the password would corrupt every
     * credential parsed from a CRLF list.
     */
    public function test_accepts_crlf_line_endings(): void
    {
        $result = ProxyListParser::parse("1.1.1.1:8080:user1:pass1\r\n2.2.2.2:9090:user2:pass2\r\n");

        $this->assertCount(2, $result, 'Both CRLF-terminated lines must parse.');
        $this->assertSame('pass1', $result[0]->password, 'A trailing \r must not leak into the password field.');
        $this->assertSame('pass2', $result[1]->password);
    }

    /**
     * Trailing whitespace (spaces, tabs) after the last field must be
     * trimmed before matching, the same way CRLF is.
     */
    public function test_accepts_trailing_tab(): void
    {
        $result = ProxyListParser::parse("1.1.1.1:8080:user1:pass1 \t\n");

        $this->assertCount(1, $result, 'Trailing whitespace must not prevent a match.');
        $this->assertSame('pass1', $result[0]->password, 'Trailing whitespace must not leak into the password.');
    }

    /**
     * A 6-digit port is syntactically excluded by the regex's `\d{1,5}`
     * capture and must be dropped rather than parsed and range-checked.
     */
    public function test_drops_a_six_digit_port(): void
    {
        $result = ProxyListParser::parse("1.1.1.1:123456:u:p\n");

        $this->assertEmpty($result, 'A port outside 1-65535 (here, 6 digits) must be dropped silently.');
    }

    /**
     * A line with an extra colon-delimited field does not match the
     * 4-field shape and must be dropped, not mis-split into 4 of the 5.
     */
    public function test_drops_a_five_field_line(): void
    {
        $result = ProxyListParser::parse("this:is:malformed:has:extra\n");

        $this->assertEmpty($result, 'A 5-field line must not be mis-split into a 4-field match.');
    }

    /**
     * An empty body is not an exceptional condition, just a source with
     * nothing usable in it; the caller (Step 5) is the one that decides
     * whether zero rows means "refuse to sweep".
     */
    public function test_empty_body_returns_empty_list_without_exception(): void
    {
        $result = ProxyListParser::parse('');

        $this->assertSame([], $result, 'An empty body must return an empty list, not throw.');
    }

    /**
     * The password field forbids only `:` (which would misdirect the
     * split); `@`, `/` and `#` are ordinary bytes here because this parser
     * never builds a URL, so it must return them byte for byte rather than
     * silently truncating or percent-encoding.
     */
    public function test_password_with_at_slash_and_hash_survives_intact(): void
    {
        $result = ProxyListParser::parse('1.2.3.4:8080:user:p@ss/word#1');

        $this->assertCount(1, $result, 'A password containing @, / and # is legal and must parse.');
        $this->assertSame(
            'p@ss/word#1',
            $result[0]->password,
            'The password must survive byte for byte; this parser never builds a URL.',
        );
    }
}
