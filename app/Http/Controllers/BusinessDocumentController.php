<?php
namespace App\Http\Controllers;
use App\Models\Document;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Inertia\Inertia;
class BusinessDocumentController extends Controller
{
    public const TYPES = ['vehicle' => ['transport_vehicles', 'transport.view', 'transport.manage'], 'driver' => ['transport_drivers', 'transport.view', 'transport.manage'], 'vehicle_maintenance' => ['vehicle_maintenance', 'transport.view', 'transport.manage'], 'purchase' => ['purchase_requests', 'inventory.view', 'inventory.manage'], 'asset' => ['fixed_assets', 'inventory.view', 'inventory.manage']];
    public function index(Request $r) { $allowed = array_keys(array_filter(self::TYPES, fn ($row) => $r->user()->can($row[1]))); abort_unless($allowed, 403); return Inertia::render('Documents/Business', ['types' => $allowed, 'documents' => Document::whereIn('documentable_type', $allowed)->latest()->paginate(20)]); }
    public function store(Request $r) { $data = $r->validate(['type' => ['required', \Illuminate\Validation\Rule::in(array_keys(self::TYPES))], 'record_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx,xlsx', 'max:10240']]); $type = self::TYPES[$data['type']]; abort_unless($r->user()->can($type[2]) && DB::table($type[0])->where('id', $data['record_id'])->exists(), 403); $file = $r->file('file'); $document = Document::create(['documentable_type' => $data['type'], 'documentable_id' => $data['record_id'], 'title' => $data['title'], 'category' => $data['type'], 'path' => $file->store('business-documents', 'local'), 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'is_private' => true, 'uploaded_by' => $r->user()->id]); Audit::record('business_document_uploaded', $document, [], ['type' => $data['type'], 'record_id' => $data['record_id']], null, 'Private operational document uploaded'); return back()->with('success', 'Private supporting document saved.'); }
    public function download(Request $r, Document $document) { $type = self::TYPES[$document->documentable_type] ?? null; abort_unless($type && $r->user()->can($type[1]), 403); Audit::record('business_document_downloaded', $document, [], [], null, 'Private operational document downloaded'); return Storage::disk('local')->download($document->path, $document->original_name, ['X-Content-Type-Options' => 'nosniff']); }
}
