<?php

namespace SocialDept\AtpAppview\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use SocialDept\AtpAppview\Spaces\SpaceBlobs;
use SocialDept\AtpAppview\Spaces\SpaceBlobUrls;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\SpaceRef;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

/**
 * Serves one blob of one member in a followed space, at a URL
 * SpaceBlobUrls signed for a viewer allowed to read it. The blob comes
 * from the disk it is kept on, or is fetched with the space's sync
 * credential, checked against its CID and kept.
 *
 * It is sent as private, cached by the browser no longer than the URL
 * lasts, with the type the URL carries, and as an attachment unless it is
 * an image, a video or audio.
 */
class SpaceBlobController
{
    public function __invoke(Request $request, string $did, string $cid, SpaceSync $sync, SpaceBlobs $blobs): SymfonyResponse
    {
        if (! URL::hasCorrectSignature($request, (bool) config('atp-appview.spaces.blobs.absolute', true))) {
            return $this->error(403, 'InvalidSignature', 'The URL is not signed, or not as it was given.');
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->error(403, 'ExpiredSignature', 'The URL has expired.');
        }

        $space = SpaceRef::tryParse((string) $request->query('space'));

        if ($space === null || ! SpaceBlobs::isCid($cid)) {
            return $this->error(400, 'InvalidRequest', 'Not a space and a blob CID.');
        }

        if (! $sync->follows($space)) {
            return $this->error(404, 'SpaceNotFound', 'The space is not followed, or was deleted.');
        }

        $headers = $this->headers($request, $cid);

        if ($blobs->has($space, $cid)) {
            $stream = $blobs->stream($space, $cid);

            return new StreamedResponse(function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            }, 200, $headers + ['Content-Length' => (string) $blobs->size($space, $cid)]);
        }

        try {
            $bytes = $blobs->fetch($space, $did, $cid);
        } catch (SpacesException|UnexpectedValueException $e) {
            report($e);

            return $this->error(502, 'UpstreamFailure', 'The blob could not be fetched from its host.');
        }

        if ($bytes === null) {
            return $this->error(503, 'SpaceUnavailable', 'The space has no usable credential to fetch the blob with. It resumes once a viewer enters it.');
        }

        return new Response($bytes, 200, $headers + ['Content-Length' => (string) strlen($bytes)]);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(Request $request, string $cid): array
    {
        $type = SpaceBlobUrls::mimeType($request->query('type'));
        $lifetime = max(0, (int) $request->query('expires') - time());

        $headers = [
            'Content-Type' => $type,
            'Cache-Control' => "private, max-age={$lifetime}",
            'X-Content-Type-Options' => 'nosniff',
            // A blob opened on its own runs nothing, as a PDS serves blobs.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        // An SVG is an image that can carry script, so it is never shown
        // in place either. An <img> ignores the disposition.
        if (! preg_match('~^(image|video|audio)/~', $type) || str_starts_with($type, 'image/svg')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$cid.'"';
        }

        return $headers;
    }

    protected function error(int $status, string $error, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'message' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
