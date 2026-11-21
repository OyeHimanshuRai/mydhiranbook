<?php
namespace Tests\Feature;
use Tests\TestCase;
class NoJewelFlowReferencesTest extends TestCase
{
    public function test_no_jewelflow_or_erp_references_in_app_routes_views(): void
    {
        $hits=[];
        foreach (['app','routes','resources/views','config'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir),\FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!$f->isFile()) { continue; }
                $text=file_get_contents($f->getPathname());
                if ($f->getFilename()==='RuntimeGuard.php') { $text=str_replace('/home/himanshu/Desktop/Dhiran ERP','',$text); }
                if (preg_match('/jewelflows?|\bERP\b/i',$text,$m)) { $hits[]=str_replace(base_path().'/','',$f->getPathname()).': '.$m[0]; }
            }
        }
        $this->assertSame([],$hits);
    }
}
