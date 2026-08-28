<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrderLine;
use App\Models\OrderPhoto;
use App\Models\Part;
use App\Models\PartFile;
use App\Models\PartLink;
use App\Models\PartMedia;
use App\Models\PartPriceBreak;
use App\Models\PartQuote;
use App\Models\PartTimeEntry;

/**
 * Everything the part page needs, assembled once.
 *
 * There used to be two part pages — one for the client, one for Junction — and
 * they had already drifted: the free-issue wording differed, one had the media
 * library and the other a photo strip, only one offered archiving. A part is
 * one thing, so it is one page, and the difference between the two audiences is
 * a handful of `if`s in the template rather than a second copy to keep in step.
 *
 * The page is rendered at two URLs — `/parts/{id}` and `/staff/parts/{id}` —
 * because Junction's own actions have to sit behind the staff middleware and
 * every existing link into the staff area points at the staff path. Same
 * template, same payload, two doors.
 */
final class PartView
{
    /**
     * @param array<string,mixed> $part a row from Part::find()
     * @return array<string,mixed>
     */
    public static function payload(array $part): array
    {
        $partId = (int) $part['id'];
        $media = PartMedia::forPart($partId);
        $draft = PartQuote::draft($partId);
        $quoteLines = PartQuote::lines($partId);

        return [
            'title' => $part['cpn'],
            'part' => $part,
            'files' => PartFile::forPart($partId),
            'mainPhoto' => PartMedia::mainPhoto($partId),
            'attachments' => PartMedia::groupAttachments($media),
            'altNumbers' => Part::alternateNumbers($partId),
            'freeIssueMaterials' => Part::freeIssueMaterials($partId),
            'linkedParts' => PartLink::forPart($partId),
            'orderLines' => OrderLine::forPart($partId),
            // Attachments filed against an order and tagged as showing this
            // part. In the payload for both audiences as everything else is;
            // the template shows them to Junction only, because the order
            // page's own photos section is Junction's.
            'orderMedia' => OrderPhoto::forPart($partId),

            // The itemised build times, both kinds. Junction's, and the
            // template says so.
            'timeEntries' => PartTimeEntry::bothForPart($partId),

            // Quantity/price pairs. `target` is the client's and is shown to
            // them; `quoted` is Junction's answer. Both are assembled here and
            // gated in the template, like everything else on this page.
            'priceBreaks' => PartPriceBreak::bothForPart($partId),

            // The quoting scratchpad. Worked out live rather than read from
            // the cached total, because the figures it adds up — the estimate,
            // the material cost — move without it.
            'quoteDraft' => $draft,
            'quoteLines' => $quoteLines,
            'quoteResult' => PartQuote::calculate($part, $draft, $quoteLines),
            'quoteDefaults' => PartQuote::defaults(),
        ];
    }
}
