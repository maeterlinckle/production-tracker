<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Upload;
use App\Models\PartDrawing;
use App\Models\PartFile;

/**
 * Taking a drawing upload, from either side of the application.
 *
 * The client's new-part form, the client's part page and Junction's part page
 * all accept drawings, and all three have to agree about what a submission
 * means — which named drawing it belongs to, whether that drawing is new, and
 * what happens when the name is already taken. Three copies of that agreement
 * would be three chances to differ.
 *
 * A submission says which drawing it is for in one of two ways:
 *
 *   drawing_id    an existing drawing on this part; the files become its next
 *                 revisions
 *   drawing_name  a new drawing, created on the spot
 *
 * A name that is already in use is not an error. Two people adding "Op 20
 * detail" minutes apart mean the same drawing, and the second upload becoming
 * its next revision is what both of them intended — see PartDrawing::create().
 */
final class DrawingUpload
{
    /**
     * @param array<string,mixed> $part a row from Part::find()
     * @return array{uploaded:int,errors:array<int,string>,drawing:?array<string,mixed>}
     */
    public static function handle(array $part, int $userId): array
    {
        $partId = (int) $part['id'];
        $files = Upload::files('drawings');
        $result = ['uploaded' => 0, 'errors' => [], 'drawing' => null];

        if ($files === []) {
            return $result;
        }

        $drawing = self::resolveDrawing($partId, $userId, $result);
        if ($drawing === null) {
            return $result;
        }

        $result['drawing'] = $drawing;

        $allowed = Config::get('uploads.drawing.extensions');
        $maxBytes = (int) Config::get('uploads.drawing.max_bytes');

        foreach ($files as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                $result['errors'][] = $error;
                continue;
            }

            $relativePath = Upload::store($file, 'drawings/' . $partId);
            $absolutePath = Upload::absolutePath($relativePath);
            $mime = $absolutePath !== null ? Upload::detectMime($absolutePath) : null;

            PartFile::create([
                'part_id' => $partId,
                'drawing_id' => (int) $drawing['id'],
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $mime,
                'file_size' => (int) $file['size'],
                'uploaded_by' => $userId,
            ]);
            $result['uploaded']++;
        }

        return $result;
    }

    /**
     * Which drawing these files belong to.
     *
     * A drawing chosen from the list has to actually be on this part: the id
     * comes off a form, and a drawing belonging to somebody else's part must
     * not collect revisions through this one's URL.
     *
     * @param array{uploaded:int,errors:array<int,string>,drawing:?array<string,mixed>} $result
     * @return array<string,mixed>|null
     */
    private static function resolveDrawing(int $partId, int $userId, array &$result): ?array
    {
        $drawingId = (int) Request::post('drawing_id', 0);

        if ($drawingId > 0) {
            $drawing = PartDrawing::find($drawingId);
            if ($drawing === null || (int) $drawing['part_id'] !== $partId) {
                $result['errors'][] = 'That drawing is not on this part.';

                return null;
            }

            return $drawing;
        }

        $name = trim((string) Request::post('drawing_name', ''));
        if ($name === '') {
            $result['errors'][] = 'Give the drawing a short name — it is how it is told apart from the others on this part.';

            return null;
        }

        return PartDrawing::find(PartDrawing::create($partId, $name, $userId));
    }

    /**
     * Say what happened, once.
     *
     * The name is in the message because an upload that quietly went to the
     * wrong drawing is the mistake this whole feature makes possible, and
     * naming the destination is what lets somebody spot it straight away.
     *
     * @param array{uploaded:int,errors:array<int,string>,drawing:?array<string,mixed>} $result
     */
    public static function flash(array $result): void
    {
        foreach ($result['errors'] as $error) {
            Flash::error($error);
        }

        if ($result['uploaded'] === 0 || $result['drawing'] === null) {
            return;
        }

        $name = (string) $result['drawing']['name'];

        Flash::success($result['uploaded'] === 1
            ? 'Uploaded as the current revision of "' . $name . '". The one before it is kept and stays viewable.'
            : $result['uploaded'] . ' files uploaded to "' . $name . '"; the last is the current revision.');
    }

    /** Both at once, for the callers that have nothing else to add. */
    public static function handleAndFlash(array $part, ?int $userId = null): void
    {
        self::flash(self::handle($part, $userId ?? (int) Auth::id()));
    }
}
