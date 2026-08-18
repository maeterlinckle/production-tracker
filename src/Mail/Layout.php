<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Services\Branding;

/**
 * The fixed shell every HTML message is sent in.
 *
 * Deliberately not editable. What an administrator writes is the *content* of a
 * message; the masthead, the spacing and the footer are the product's, and
 * making them editable would mean a dozen chances to produce a broken layout
 * and no way to improve all of them at once.
 *
 * Written the way email has to be written rather than the way a web page is:
 * tables for structure because Outlook lays out with Word's engine, inline
 * styles on the containers because several clients discard a <style> block, and
 * a 600px body because that is what fits a preview pane.
 */
final class Layout
{
    /** The Content-ID the logo is attached under, when there is one. */
    public const LOGO_CID = 'branding-logo';

    private const INK    = '#0f172a';
    private const MUTED  = '#64748b';
    private const RULE   = '#e2e8f0';
    private const ACCENT = '#1d4ed8';
    private const PAGE   = '#f1f5f9';
    private const FONT   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    public static function hasLogo(): bool
    {
        return self::logoPath() !== null;
    }

    /**
     * The absolute path of the logo to embed, or null.
     *
     * The light variant: a message is read on a white card whatever the mail
     * client's own theme, which is the same reasoning as printing.
     */
    public static function logoPath(): ?string
    {
        return Branding::printablePath();
    }

    /**
     * Wrap message content in the shell.
     *
     * @param string $content Already-merged HTML, with values already escaped
     *                        by Merge::render()
     */
    public static function wrap(string $content, string $subject = ''): string
    {
        $escape = static fn (string $value): string
            => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $product   = $escape((string) Config::get('app.product', 'Production Tracker'));
        $fullName  = $escape((string) Config::get('app.full_name', 'Production Tracker by Junction'));
        $tagline   = $escape((string) Config::get('app.tagline', 'Job Shop Order Tracking'));
        $vendor    = $escape((string) Config::get('app.vendor', 'Junction Inc Ltd'));
        $vendorUrl = $escape((string) Config::get('app.vendor_url', ''));
        $appUrl    = $escape(rtrim((string) Config::get('app.url', ''), '/'));
        $title     = $escape($subject);

        $ink    = self::INK;
        $muted  = self::MUTED;
        $rule   = self::RULE;
        $accent = self::ACCENT;
        $page   = self::PAGE;
        $font   = self::FONT;
        $cid    = self::LOGO_CID;

        $masthead = self::hasLogo()
            ? '<img src="cid:' . $cid . '" alt="' . $product . '" height="40"'
                . ' style="display:block;height:40px;width:auto;max-width:220px;border:0;outline:none;text-decoration:none;">'
            : '<span style="font-size:19px;font-weight:700;color:' . $ink . ';">' . $product . '</span>';

        $vendorLine = $vendorUrl === ''
            ? $vendor
            : '<a href="' . $vendorUrl . '" style="color:' . $muted . ';">' . $vendor . '</a>';

        $appLink = $appUrl === ''
            ? ''
            : '<br><a href="' . $appUrl . '" style="color:' . $muted . ';">' . $appUrl . '</a>';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  /* Clients that honour a style block get tidier spacing. The rest inherit the
     container's font and are perfectly readable without it. */
  .content p { margin: 0 0 14px; }
  .content ul, .content ol { margin: 0 0 14px; padding-left: 22px; }
  .content li { margin: 0 0 6px; }
  .content h2 { margin: 0 0 10px; font-size: 17px; }
  .content a { color: {$accent}; }
  .items { margin: 0 0 16px; padding: 12px 14px; background: #f8fafc; border-left: 3px solid {$accent}; }
</style>
</head>
<body style="margin:0;padding:0;background:{$page};">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:{$page};">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
             style="width:600px;max-width:600px;background:#ffffff;border:1px solid {$rule};border-radius:10px;">
        <tr>
          <td style="padding:20px 24px;border-bottom:3px solid {$accent};">{$masthead}</td>
        </tr>
        <tr>
          <td class="content" style="padding:24px;font-family:{$font};font-size:15px;line-height:1.55;color:{$ink};">
{$content}
          </td>
        </tr>
        <tr>
          <td style="padding:16px 24px;border-top:1px solid {$rule};font-family:{$font};font-size:12px;line-height:1.5;color:{$muted};">
            {$fullName}<br>
            {$tagline} &middot; {$vendorLine}{$appLink}
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }
}
