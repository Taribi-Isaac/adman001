<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateStoragePermissionsTest extends TestCase
{
    public function test_private_disk_creates_group_writable_directories(): void
    {
        $root = sys_get_temp_dir().'/adman-perm-'.uniqid();
        $previousUmask = umask(0002);

        try {
            $disk = Storage::build(array_merge(config('filesystems.disks.local'), ['root' => $root]));
            $disk->put('documents/invoice_pdf/App/Models/Invoice/9/doc.pdf', 'pdf');

            foreach (['documents', 'documents/invoice_pdf/App/Models/Invoice', 'documents/invoice_pdf/App/Models/Invoice/9'] as $dir) {
                $this->assertSame('770', substr(sprintf('%o', fileperms($root.'/'.$dir)), -3), $dir);
            }
        } finally {
            umask($previousUmask);
            File::deleteDirectory($root);
        }
    }
}
