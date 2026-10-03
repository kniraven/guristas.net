<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach(['dom'=>'DOMDocument','zip'=>'ZipArchive','fileinfo'=>'finfo'] as $extension=>$class) {
 if(!class_exists($class)) {fwrite(STDERR,"Missing PHP extension: $extension\n");exit(1);}
}
require $root.'/app/services/RichText.php';
$cases=[
 ['<p onclick="alert(1)">Hello <strong>pilot</strong></p><script>alert(1)</script>', ['Hello','<strong>pilot</strong>'], ['onclick','script','alert']],
 ['<a href="javascript:alert(1)">unsafe</a><a href="https://guristas.net">safe</a>', ['unsafe','href="https://guristas.net"'], ['javascript:']],
 ['<svg onload="alert(1)"></svg><img src=x onerror="alert(2)"><iframe src="https://example.com"></iframe>', [], ['svg','img','iframe','onerror','onload']],
 ['<ul><li>One</li><li>Two</li></ul>', ['<ul>','<li>One</li>'], []],
];
foreach($cases as [$input,$present,$absent]) {
 $clean=guristas_rich_clean($input);
 foreach($present as $needle) if(!str_contains($clean,$needle)) {fwrite(STDERR,"Rich text check failed: missing $needle\n");exit(1);}
 foreach($absent as $needle) if(str_contains($clean,$needle)) {fwrite(STDERR,"Rich text check failed: retained $needle\n");exit(1);}
}
echo "PHP extensions and rich-text security checks passed.\n";
