<?php
declare(strict_types=1);

/** Private files are streamed only after authentication and tenant checks — never served directly. */
return static function (Router $r): void {
    $stream = static function (?string $bytes, string $mime, string $name, bool $inline = true): never {
        if ($bytes === null) {
            throw ApiException::notFound('File');
        }
        if ($mime === 'application/octet-stream') {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: $mime;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
        echo $bytes;
        exit;
    };

    $r->get('/files/student-photo/{id}', static function (Ctx $c, array $p) use ($stream) {
        $s = $c->assertStudent((int) $p['id']);
        $stream($s['photo_path'] ? Upload::readStored($s['photo_path']) : null, 'image/jpeg', 'photo.jpg');
    });

    $r->get('/files/teacher-photo/{id}', static function (Ctx $c, array $p) use ($stream) {
        $t = Crud::find('teachers', (int) $p['id'], $c->academy(), 'Teacher');
        $stream($t['photo_path'] ? Upload::readStored($t['photo_path']) : null, 'image/jpeg', 'photo.jpg');
    }, ['roles' => ['owner', 'teacher']]);

    $r->get('/files/admission-photo/{id}', static function (Ctx $c, array $p) use ($stream) {
        $a = Crud::find('admissions', (int) $p['id'], $c->academy(), 'Application');
        $stream($a['photo_path'] ? Upload::readStored($a['photo_path']) : null, 'image/jpeg', 'photo.jpg');
    }, ['roles' => ['owner']]);

    $r->get('/files/document/{id}', static function (Ctx $c, array $p) use ($stream) {
        $d = Crud::find('student_documents', (int) $p['id'], $c->academy(), 'Document');
        if ($d['deleted_at'] || !$c->can('documents.manage')) {
            throw ApiException::notFound('Document');
        }
        $c->assertStudent((int) $d['student_id']);
        Audit::log($c, 'document.view', 'student_documents', $d['id']);
        $ext = $d['mime'] === 'application/pdf' ? 'pdf' : 'jpg';
        $stream(Upload::readStored($d['storage_path']), $d['mime'], "document-{$d['id']}.$ext", Http::query('download') !== '1');
    }, ['roles' => ['owner', 'teacher']]);

    $r->get('/files/admission-document/{id}', static function (Ctx $c, array $p) use ($stream) {
        $a = Crud::find('admissions', (int) $p['id'], $c->academy(), 'Application');
        $stream($a['document_path'] ? Upload::readStored($a['document_path']) : null, 'application/octet-stream', 'document');
    }, ['roles' => ['owner']]);

    // receipt PDF (staff with fee access, or the family that owns it)
    $r->get('/receipts/{id}/pdf', static function (Ctx $c, array $p) use ($stream) {
        $rc = DB::one('SELECT * FROM receipts WHERE id = ? AND academy_id = ?', [(int) $p['id'], $c->academy()]);
        if (!$rc) {
            throw ApiException::notFound('Receipt');
        }
        if ($c->isStaff() && !$c->can('fees.view')) {
            throw ApiException::forbidden();
        }
        $c->assertStudent((int) $rc['student_id']);
        $stream(ReceiptService::pdf((int) $rc['id']), 'application/pdf', str_replace('/', '-', $rc['receipt_no']) . '.pdf', Http::query('download') !== '1');
    }, ['roles' => ['owner', 'teacher', 'parent', 'student']]);
};
