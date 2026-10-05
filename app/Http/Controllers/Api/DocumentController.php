<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentDocument;
use App\Models\TestObject;
use App\Models\TestObjectDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Fayl biriktirish — TZ 1.9 (uskuna sertifikatlari, aktlar, yo'riqnomalar)
 * va TZ 2.8 (sinov obyektining hamroh hujjatlari).
 */
class DocumentController extends Controller
{
    use LogsActivity;

    private const MAX_SIZE_KB = 20480; // 20 MB

    private const MIMES = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,zip,rar';

    /* ================= USKUNA HUJJATLARI ================= */

    public function equipmentIndex(Equipment $equipment)
    {
        return $equipment->documents()->with('uploader:id,name')->get();
    }

    public function equipmentStore(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'type'  => ['required', 'in:calibration_certificate,maintenance_act,manual,other'],
            'title' => ['required', 'string', 'max:191'],
            'file'  => ['required', 'file', 'max:' . self::MAX_SIZE_KB, 'mimes:' . self::MIMES],
        ]);

        $file = $request->file('file');
        $path = $file->store("equipment/{$equipment->id}", 'public');

        $document = $equipment->documents()->create([
            'type'          => $data['type'],
            'title'         => $data['title'],
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'file_size'     => $file->getSize(),
            'uploaded_by'   => auth()->id(),
        ]);

        $this->logActivity('created', $document, null, "Hujjat yuklandi: {$data['title']}");

        return response()->json($document->load('uploader:id,name'), 201);
    }

    public function equipmentDestroy(Equipment $equipment, EquipmentDocument $document)
    {
        abort_if($document->equipment_id !== $equipment->id, 404);

        Storage::disk('public')->delete($document->file_path);
        $title = $document->title;
        $document->delete();

        $this->logActivity('deleted', $document, null, "Hujjat o'chirildi: {$title}");

        return response()->json(['message' => 'Hujjat o\'chirildi']);
    }

    /* ================= SINOV OBYEKTI HUJJATLARI ================= */

    public function testObjectIndex(TestObject $testObject)
    {
        return $testObject->documents()->with('uploader:id,name')->get();
    }

    public function testObjectStore(Request $request, TestObject $testObject)
    {
        $data = $request->validate([
            'type'  => ['required', 'in:waybill,acceptance_act,disposal_act,protocol,other'],
            'title' => ['required', 'string', 'max:191'],
            'file'  => ['required', 'file', 'max:' . self::MAX_SIZE_KB, 'mimes:' . self::MIMES],
        ]);

        $file = $request->file('file');
        $path = $file->store("test-objects/{$testObject->id}", 'public');

        $document = $testObject->documents()->create([
            'type'          => $data['type'],
            'title'         => $data['title'],
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'file_size'     => $file->getSize(),
            'uploaded_by'   => auth()->id(),
        ]);

        $this->logActivity('created', $document, null, "Hujjat yuklandi: {$data['title']}");

        return response()->json($document->load('uploader:id,name'), 201);
    }

    public function testObjectDestroy(TestObject $testObject, TestObjectDocument $document)
    {
        abort_if($document->test_object_id !== $testObject->id, 404);

        Storage::disk('public')->delete($document->file_path);
        $title = $document->title;
        $document->delete();

        $this->logActivity('deleted', $document, null, "Hujjat o'chirildi: {$title}");

        return response()->json(['message' => 'Hujjat o\'chirildi']);
    }
}
