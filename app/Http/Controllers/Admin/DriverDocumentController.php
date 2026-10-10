<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectDriverDocumentRequest;
use App\Models\DriverDocument;
use App\Models\User;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverDocumentReviewer $reviewer) {}

    public function approve(Request $request, int $driver, int $document): RedirectResponse
    {
        $this->reviewer->approve($this->find($driver, $document), $this->admin($request));

        return back()->with('success', 'Document approved.');
    }

    public function reject(RejectDriverDocumentRequest $request, int $driver, int $document): RedirectResponse
    {
        $this->reviewer->reject($this->find($driver, $document), $this->admin($request), $request->validated('reason'));

        return back()->with('success', 'Document rejected.');
    }

    /**
     * Streams the file. Reachable only with a valid, unexpired signature (route middleware) by a
     * signed-in admin who may view drivers.
     */
    public function file(int $driver, int $document): StreamedResponse
    {
        $document = $this->find($driver, $document);
        $disk = Storage::disk((string) config('taxikosmos.documents.disk'));

        abort_unless($disk->exists($document->file_path), 404);

        return $disk->response($document->file_path, null, [
            // The stored (sniffed) type, never anything the client claimed later; never rendered as HTML.
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The document must belong to the driver in the URL.
     */
    private function find(int $driver, int $document): DriverDocument
    {
        return DriverDocument::query()->where('driver_id', $driver)->findOrFail($document);
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
