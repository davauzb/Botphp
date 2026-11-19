<?php

require_once __DIR__ . '/PptxLayouts.php';
require_once __DIR__ . '/PptxNotes.php';

class PptxGenerator
{
    private const DEFAULT_DESIGN = ['accent' => '4472C4', 'title' => '1F4E79', 'layout' => 'bars'];

    /**
     * $slides = [ ['title' => '...', 'bullets' => ['...','...'], 'image' => '/local/path.jpg' (ixtiyoriy)], ... ]
     * $design = ['accent' => 'RRGGBB', 'title' => 'RRGGBB', 'layout' => 'bars'|'sidebar'] — CONFIG.PHP'dagi
     * PPTX_DESIGNS massividan biri. Berilmasa standart (ko'k) dizayn ishlatiladi.
     */
    public static function generate(array $slides, array $design = self::DEFAULT_DESIGN): string
    {
        $design = array_merge(self::DEFAULT_DESIGN, $design);
        $filename = 'slayd_' . uniqid() . '.pptx';
        $path = GENERATED_DIR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('PPTX fayl yaratib bo\'lmadi: ' . $path);
        }

        $count = count($slides);
        $notes = [];
        foreach ($slides as $i => $slide) {
            $t = PptxNotes::clean((string)($slide['notes'] ?? ''));
            if ($t !== '') {
                $notes[$i + 1] = $t;
            }
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes($count, array_keys($notes)));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('ppt/presentation.xml', self::presentationXml($count, (bool)$notes));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentationRels($count, (bool)$notes));
        self::addNotesParts($zip, $notes, $design);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::slideMasterXml($design));
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::slideMasterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', self::slideLayoutXml());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', self::slideLayoutRels());
        $zip->addFromString('ppt/theme/theme1.xml', self::themeXml($design));

        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $imagePath = $slide['image'] ?? null;
            $hasImage = $imagePath && is_file($imagePath);
            $mediaName = '';

            if ($hasImage) {
                $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)) ?: 'jpg';
                $mediaName = "image{$n}.{$ext}";
                $zip->addFile($imagePath, "ppt/media/{$mediaName}");
            }

            $zip->addFromString(
                "ppt/slides/slide{$n}.xml",
                self::slideXml($slide, $hasImage, $n, $count, $imagePath, $design)
            );
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", self::slideRels($hasImage, $mediaName, isset($notes[$n]) ? $n : 0));
        }

        if (!$zip->close() || !is_file($path)) {
            throw new RuntimeException('PPTX faylni diskka yozib bo\'lmadi (papka mavjudmi va yozish ruxsati bormi?): ' . $path);
        }
        return $path;
    }

    /**
     * Tayyor slayd XML'laridan (PptxElements::buildPackage) to'liq PPTX yig'adi.
     * $parts = [ ['xml' => '<p:sld>...', 'media' => ['rId2' => ['name' => 'image1.jpg', 'path' => '/..']]], ... ]
     */
    public static function generateFromParts(array $parts, array $design = self::DEFAULT_DESIGN): string
    {
        $design = array_merge(self::DEFAULT_DESIGN, $design);
        $path = GENERATED_DIR . 'tahrir_' . bin2hex(random_bytes(8)) . '.pptx';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('PPTX fayl yaratib bo\'lmadi');
        }
        $count = count($parts);
        $notes = [];
        foreach ($parts as $i => $part) {
            $t = PptxNotes::clean((string)($part['notes'] ?? ''));
            if ($t !== '') {
                $notes[$i + 1] = $t;
            }
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes($count, array_keys($notes)));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('ppt/presentation.xml', self::presentationXml($count, (bool)$notes));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentationRels($count, (bool)$notes));
        self::addNotesParts($zip, $notes, $design);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::slideMasterXml($design));
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::slideMasterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', self::slideLayoutXml());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', self::slideLayoutRels());
        $zip->addFromString('ppt/theme/theme1.xml', self::themeXml($design));

        $addedMedia = [];
        foreach ($parts as $i => $part) {
            $n = $i + 1;
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';
            foreach ($part['media'] as $rid => $m) {
                if (!isset($addedMedia[$m['name']])) {
                    $zip->addFile($m['path'], 'ppt/media/' . $m['name']);
                    $addedMedia[$m['name']] = true;
                }
                $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $m['name'] . '"/>';
            }
            if (isset($notes[$n])) {
                $rels .= '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $n . '.xml"/>';
            }
            $rels .= '</Relationships>';
            $zip->addFromString("ppt/slides/slide{$n}.xml", $part['xml']);
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $rels);
        }
        if (!$zip->close() || !is_file($path)) {
            throw new RuntimeException('PPTX faylni diskka yozib bo\'lmadi (papka mavjudmi va yozish ruxsati bormi?): ' . $path);
        }
        return $path;
    }

    /** Nutq matni qismlari: notesMaster (+ theme2) va har bir izohli slayd uchun notesSlide. */
    private static function addNotesParts(ZipArchive $zip, array $notes, array $design): void
    {
        if (!$notes) {
            return;
        }
        $zip->addFromString('ppt/notesMasters/notesMaster1.xml', PptxNotes::masterXml());
        $zip->addFromString('ppt/notesMasters/_rels/notesMaster1.xml.rels', PptxNotes::masterRels());
        $zip->addFromString('ppt/theme/theme2.xml', self::themeXml($design));
        foreach ($notes as $n => $text) {
            $zip->addFromString("ppt/notesSlides/notesSlide{$n}.xml", PptxNotes::slideXml($text));
            $zip->addFromString("ppt/notesSlides/_rels/notesSlide{$n}.xml.rels", PptxNotes::slideRels($n));
        }
    }

    /** Bitta slayd XML'ini (PptxLayouts orqali) tashqaridan olish uchun — tahrirlovchi element modeliga aylantiradi. */
    public static function buildSlideXml(array $slide, int $n, int $total, ?string $imagePath, array $design): string
    {
        $design = array_merge(self::DEFAULT_DESIGN, $design);
        $hasImage = $imagePath && is_file($imagePath);
        return self::slideXml($slide, (bool)$hasImage, $n, $total, $hasImage ? $imagePath : null, $design);
    }

    private static function escape(string $t): string
    {
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function slideXml(
        array $slide,
        bool $hasImage,
        int $slideNum,
        int $totalSlides,
        ?string $imagePath,
        array $design
    ): string {
        $layout = $design['layout'];
        $title = (string)($slide['title'] ?? '');
        // Yangi formatdagi slaydlarda 'points'/'lead' bo'ladi — eski maketlar uchun bitta qatorli matnga aylantiramiz
        $bullets = isset($slide['points']) ? PptxLayouts::flatBullets($slide) : ($slide['bullets'] ?? []);

        // "cards" — eng zamonaviy uslub. 'kind' berilgan slaydlar PptxLayouts orqali xilma-xil
        // maketlarda chiziladi (ta'rif, bosqichlar, xronologiya, kartalar, taqqoslash, xulosa...).
        if ($layout === 'cards' && isset($slide['kind'])) {
            if (in_array($slide['kind'], ['title', 'thanks'], true)) {
                return PptxLayouts::edgeSlide($slide, $design, $slideNum, $totalSlides);
            }
            return PptxLayouts::contentSlide($slide, $design, $slideNum, $totalSlides, $hasImage, $imagePath);
        }

        // Eski format ('kind' yo'q) — birinchi va oxirgi slayd to'liq rangli fonda,
        // o'rtadagilar och fonda, oq/yumaloq burchakli "karta" ichida.
        if ($layout === 'cards') {
            $isEdge = ($slideNum === 1 || $slideNum === $totalSlides);
            return $isEdge
                ? self::cardEdgeSlideXml($title, $bullets, $design, $slideNum, $totalSlides)
                : self::cardContentSlideXml($title, $bullets, $hasImage, $imagePath, $design, $slideNum, $totalSlides);
        }

        $bulletsXml = '';
        foreach ($bullets as $b) {
            $bulletsXml .= '<a:p><a:pPr><a:spcAft><a:spcPts val="800"/></a:spcAft></a:pPr><a:r><a:rPr lang="uz-UZ" sz="2200"/><a:t>' . self::escape($b) . '</a:t></a:r></a:p>';
        }
        if (!$bullets) {
            $bulletsXml = '<a:p><a:endParaRPr lang="uz-UZ"/></a:p>';
        }

        // Har bir shaklga (layout) mos ravishda sarlavha matnini qayerga va qanday
        // rangda joylashtirish kerakligini hisoblaymiz — "title_box" va "bottom_thick"
        // kabi shakllarda sarlavha rangli fon USTIDA turadi, shuning uchun oq rangda
        // bo'lishi kerak (aks holda o'qib bo'lmaydi).
        $layout = $design['layout'];
        $leftEdge = $layout === 'sidebar' ? 685800 : 457200;
        $titleY = 274638;
        $titleColor = $design['title'];
        $onColoredBg = false;

        if ($layout === 'title_box') {
            $titleY = 320000;
            $titleColor = 'FFFFFF';
            $onColoredBg = true;
        } elseif ($layout === 'bottom_thick') {
            $leftEdge = 700000; // chap yuqori burchakdagi kichik shaklga urilib qolmasin
        } elseif ($layout === 'frame') {
            $leftEdge = 550000;
            $titleY = 380000;
        }

        $titleX = $leftEdge;
        // "corner_circle" shaklida yuqori-o'ng burchakda dekorativ doira bor —
        // sarlavha matni uzun bo'lsa unga tegib qolmasligi uchun kenglikni
        // qo'shimcha toraytiramiz.
        $rightMargin = $layout === 'corner_circle' ? 1700000 : 228600;
        $titleW = 9144000 - $leftEdge - $rightMargin;

        // Rasm bo'lsa, matn qutisini chapga torsiytiramiz va o'ngga rasm qo'yamiz
        $bodyY = $onColoredBg ? 1750000 : 1500000;
        $bodySpPr = $hasImage
            ? '<p:spPr><a:xfrm><a:off x="' . $leftEdge . '" y="' . $bodyY . '"/><a:ext cx="4267200" cy="4500000"/></a:xfrm></p:spPr>'
            : '<p:spPr><a:xfrm><a:off x="' . $leftEdge . '" y="' . $bodyY . '"/><a:ext cx="' . $titleW . '" cy="4500000"/></a:xfrm></p:spPr>';

        $pictureXml = $hasImage ? self::pictureXml($imagePath) : '';
        $titleBoxBg = $onColoredBg
            ? '<a:solidFill><a:srgbClr val="' . $design['accent'] . '"/></a:solidFill>'
            : '<a:noFill/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
       xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
<p:sp>
  <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . $titleX . '" y="' . $titleY . '"/><a:ext cx="' . $titleW . '" cy="1141413"/></a:xfrm>' . $titleBoxBg . '</p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="uz-UZ" b="1" sz="2800"><a:solidFill><a:srgbClr val="' . $titleColor . '"/></a:solidFill></a:rPr><a:t>' . self::escape($title) . '</a:t></a:r></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>
  ' . $bodySpPr . '
  <p:txBody><a:bodyPr/><a:lstStyle/>' . $bulletsXml . '</p:txBody>
</p:sp>
' . $pictureXml . self::slideNumberXml($slideNum, $totalSlides, $layout === 'bottom_thick') . '
</p:spTree></p:cSld>
<p:clrMapOvr><a:overrideClrMapping bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:clrMapOvr>
</p:sld>';
    }

    /**
     * "cards" uslubida MUQOVA va YAKUNIY slayd — to'liq rangli fon, katta oq
     * sarlavha, pastda ixtiyoriy kichik "brend" yorlig'i (agar sozlangan bo'lsa).
     */
    private static function cardEdgeSlideXml(string $title, array $bullets, array $design, int $slideNum, int $totalSlides): string
    {
        $subtitle = $bullets ? implode('   •   ', array_map([self::class, 'escape'], $bullets)) : '';
        $brand = (defined('SLIDE_BRANDING_TAG') && SLIDE_BRANDING_TAG !== '')
            ? '<a:p><a:r><a:rPr lang="uz-UZ" sz="1400"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:rPr><a:t>' . self::escape(SLIDE_BRANDING_TAG) . '</a:t></a:r></a:p>'
            : '<a:p><a:endParaRPr lang="uz-UZ"/></a:p>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
       xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
' . self::shapeRect('EdgeBg', 0, 0, 9144000, 6858000, $design['accent'], 20) . '
<p:sp>
  <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="685800" y="2700000"/><a:ext cx="7772400" cy="1200000"/></a:xfrm><a:noFill/></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="uz-UZ" b="1" sz="4000"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:rPr><a:t>' . self::escape($title) . '</a:t></a:r></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="685800" y="3950000"/><a:ext cx="7772400" cy="700000"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="uz-UZ" sz="1800"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:rPr><a:t>' . $subtitle . '</a:t></a:r></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="6" name="Brand"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="685800" y="6300000"/><a:ext cx="4000000" cy="300000"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/>' . $brand . '</p:txBody>
</p:sp>
</p:spTree></p:cSld>
<p:clrMapOvr><a:overrideClrMapping bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:clrMapOvr>
</p:sld>';
    }

    /**
     * "cards" uslubida ICHKI (mazmunli) slayd — och tusdagi fon, sarlavha rangli
     * matn sifatida, mazmun oq/yumaloq burchakli "karta" ichida, rasm ham xuddi
     * shunday yumaloq burchakli karta ko'rinishida (asl nisbati saqlangan holda).
     */
    private static function cardContentSlideXml(
        string $title,
        array $bullets,
        bool $hasImage,
        ?string $imagePath,
        array $design,
        int $slideNum,
        int $totalSlides
    ): string {
        $bulletsXml = '';
        foreach ($bullets as $b) {
            $bulletsXml .= '<a:p><a:pPr><a:spcAft><a:spcPts val="800"/></a:spcAft></a:pPr><a:r><a:rPr lang="uz-UZ" sz="2000"/><a:t>' . self::escape($b) . '</a:t></a:r></a:p>';
        }
        if (!$bullets) {
            $bulletsXml = '<a:p><a:endParaRPr lang="uz-UZ"/></a:p>';
        }

        $cardW = $hasImage ? 4800000 : 7999600;
        $cardX = 572000;
        $cardY = 1550000;
        $cardH = 4900000;

        $pictureXml = $hasImage ? self::roundedPictureXml($imagePath, 5658000, $cardY, 2913600, $cardH, $design['accent']) : '';
        $bgColor = self::lightenColor($design['accent'], 0.92);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
       xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
' . self::shapeRect('ContentBg', 0, 0, 9144000, 6858000, $bgColor, 21) . '
<p:sp>
  <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="572000" y="380000"/><a:ext cx="8000000" cy="900000"/></a:xfrm><a:noFill/></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="uz-UZ" b="1" sz="2800"><a:solidFill><a:srgbClr val="' . $design['title'] . '"/></a:solidFill></a:rPr><a:t>' . self::escape($title) . '</a:t></a:r></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="3" name="Card"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . $cardX . '" y="' . $cardY . '"/><a:ext cx="' . $cardW . '" cy="' . $cardH . '"/></a:xfrm>
    <a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 6000"/></a:avLst></a:prstGeom>
    <a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>
    <a:ln w="19050"><a:solidFill><a:srgbClr val="' . $design['accent'] . '"/></a:solidFill></a:ln>
  </p:spPr>
  <p:txBody><a:bodyPr lIns="360000" tIns="360000" rIns="360000"/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="4" name="Body"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . ($cardX + 360000) . '" y="' . ($cardY + 360000) . '"/><a:ext cx="' . ($cardW - 720000) . '" cy="' . ($cardH - 720000) . '"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/>' . $bulletsXml . '</p:txBody>
</p:sp>
' . $pictureXml . self::slideNumberXml($slideNum, $totalSlides, false) . '
</p:spTree></p:cSld>
</p:sld>';
    }

    /**
     * Rasmni yumaloq burchakli qilib (asl nisbatini saqlab, markazlashtirib)
     * joylashtiradi — "cards" uslubi uchun, namunadagi kabi.
     */
    private static function roundedPictureXml(string $imagePath, int $boxX, int $boxY, int $boxW, int $boxH, string $borderColor): string
    {
        $offX = $boxX;
        $offY = $boxY;
        $extW = $boxW;
        $extH = $boxH;

        $size = @getimagesize($imagePath);
        if ($size && $size[0] > 0 && $size[1] > 0) {
            $imgRatio = $size[0] / $size[1];
            $boxRatio = $boxW / $boxH;
            if ($imgRatio > $boxRatio) {
                $extW = $boxW;
                $extH = (int)round($boxW / $imgRatio);
                $offY = $boxY + (int)round(($boxH - $extH) / 2);
            } else {
                $extH = $boxH;
                $extW = (int)round($boxH * $imgRatio);
                $offX = $boxX + (int)round(($boxW - $extW) / 2);
            }
        }

        return '<p:pic>
  <p:nvPicPr><p:cNvPr id="5" name="Picture"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>
  <p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>
  <p:spPr>
    <a:xfrm><a:off x="' . $offX . '" y="' . $offY . '"/><a:ext cx="' . $extW . '" cy="' . $extH . '"/></a:xfrm>
    <a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 6000"/></a:avLst></a:prstGeom>
    <a:ln w="19050"><a:solidFill><a:srgbClr val="' . $borderColor . '"/></a:solidFill></a:ln>
  </p:spPr>
</p:pic>';
    }

    /**
     * Hex rangni oq bilan aralashtirib, och tusini hisoblaydi — "cards" uslubidagi
     * fon uchun (masalan to'liq to'q ko'k emas, juda och ko'ksimon fon).
     */
    private static function lightenColor(string $hex, float $amount = 0.92): string
    {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = (int)round($r + (255 - $r) * $amount);
        $g = (int)round($g + (255 - $g) * $amount);
        $b = (int)round($b + (255 - $b) * $amount);
        return sprintf('%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Har slayd pastki o'ng burchagida kichik "N / jami" ko'rinishidagi raqam —
     * taqdimotlarning aksariyati shu tarzda ko'rsatadi, dizaynni "tugallangan" qiladi.
     */
    private static function slideNumberXml(int $slideNum, int $totalSlides, bool $onColoredBand = false): string
    {
        $color = $onColoredBand ? 'FFFFFF' : 'A5A5A5';
        return '<p:sp>
  <p:nvSpPr><p:cNvPr id="5" name="SlideNumber"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="8300000" y="6600000"/><a:ext cx="700000" cy="200000"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="r"/><a:r><a:rPr lang="uz-UZ" sz="1200"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:rPr><a:t>' . $slideNum . ' / ' . $totalSlides . '</a:t></a:r></a:p></p:txBody>
</p:sp>';
    }

    /**
     * Rasmni QUTI ICHIGA CHO'ZMASDAN, asl nisbatini saqlagan holda joylashtiradi
     * (markazlashtirib). Aks holda haqiqiy fotosuratlar cho'zilib/torayib,
     * buzilgan ko'rinardi — bu real test bilan aniqlangan dizayn xatosi edi.
     */
    private static function pictureXml(?string $imagePath): string
    {
        $boxX = 4953000;
        $boxY = 1500000;
        $boxW = 3733800;
        $boxH = 4700000;

        $offX = $boxX;
        $offY = $boxY;
        $extW = $boxW;
        $extH = $boxH;

        $size = $imagePath ? @getimagesize($imagePath) : false;
        if ($size && $size[0] > 0 && $size[1] > 0) {
            $imgRatio = $size[0] / $size[1];
            $boxRatio = $boxW / $boxH;
            if ($imgRatio > $boxRatio) {
                // rasm nisbatan "kengroq" — kenglikka moslab, balandligini kamaytiramiz
                $extW = $boxW;
                $extH = (int)round($boxW / $imgRatio);
                $offY = $boxY + (int)round(($boxH - $extH) / 2);
            } else {
                // rasm nisbatan "balandroq" — balandlikka moslab, kengligini kamaytiramiz
                $extH = $boxH;
                $extW = (int)round($boxH * $imgRatio);
                $offX = $boxX + (int)round(($boxW - $extW) / 2);
            }
        }

        // rId2 — slideRels() da rasm uchun ajratilgan relationship ID
        return '<p:pic>
  <p:nvPicPr><p:cNvPr id="4" name="Picture"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>
  <p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>
  <p:spPr>
    <a:xfrm><a:off x="' . $offX . '" y="' . $offY . '"/><a:ext cx="' . $extW . '" cy="' . $extH . '"/></a:xfrm>
    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
  </p:spPr>
</p:pic>';
    }

    private static function slideRels(bool $hasImage, string $mediaName, int $notesN = 0): string
    {
        $imageRel = $hasImage
            ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $mediaName . '"/>'
            : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
' . $imageRel
            . ($notesN > 0 ? '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $notesN . '.xml"/>' : '')
            . '</Relationships>';
    }

    private static function presentationXml(int $count, bool $notes = false): string
    {
        $sldIdLst = '';
        for ($i = 0; $i < $count; $i++) {
            $sldIdLst .= '<p:sldId id="' . (256 + $i) . '" r:id="rIdSlide' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>' . ($notes ? '<p:notesMasterIdLst><p:notesMasterId r:id="rIdNotesMaster"/></p:notesMasterIdLst>' : '') . '
<p:sldIdLst>' . $sldIdLst . '</p:sldIdLst>
<p:sldSz cx="9144000" cy="6858000"/>
<p:notesSz cx="6858000" cy="9144000"/>
</p:presentation>';
    }

    private static function presentationRels(int $count, bool $notes = false): string
    {
        $rels = '<Relationship Id="rIdMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        for ($i = 0; $i < $count; $i++) {
            $n = $i + 1;
            $rels .= '<Relationship Id="rIdSlide' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/>';
        }
        $rels .= '<Relationship Id="rIdTheme" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';
        if ($notes) {
            $rels .= '<Relationship Id="rIdNotesMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster" Target="notesMasters/notesMaster1.xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /**
     * Dekorativ elementlar MASTER darajasida qo'shiladi — shu bilan har bir
     * slaydda qayta yozishning hojati yo'q, avtomatik meros orqali ko'rinadi.
     * 6 xil shakl: bars (chiziqlar), sidebar (panel), corner_circle (burchakdagi
     * doira), title_box (rangli sarlavha qutisi), frame (ramka), bottom_thick
     * (qalin pastki panel + burchak belgisi).
     */
    private static function slideMasterXml(array $design): string
    {
        $accent = $design['accent'];
        $decor = match ($design['layout']) {
            'sidebar' => self::shapeRect('Sidebar', 0, 0, 228600, 6858000, $accent, 10),
            'corner_circle' => self::shapeEllipse('CornerCircle', 8100000, -700000, 1900000, 1900000, $accent, 10)
                . self::shapeEllipse('CornerCircleSmall', -400000, 5800000, 1200000, 1200000, $accent, 11),
            'title_box' => self::shapeRect('BottomLine', 0, 6766560, 9144000, 45720, $accent, 10),
            'frame' => self::shapeFrame($accent, 10),
            'bottom_thick' => self::shapeRect('BottomThick', 0, 6553200, 9144000, 304800, $accent, 10)
                . self::shapeTriangle('CornerTriangle', 0, 0, 700000, 700000, $accent, 11),
            'cards' => '', // har bir slayd o'z fonini o'zi chizadi (edge/content) — masterda qo'shimcha bezakka hojat yo'q
            default => self::shapeRect('TopAccent', 0, 0, 9144000, 91440, $accent, 10)
                . self::shapeRect('BottomAccent', 0, 6766560, 9144000, 91440, $accent, 11),
        };

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
' . $decor . '
<p:sp>
  <p:nvSpPr><p:cNvPr id="2" name="Title Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="457200" y="274638"/><a:ext cx="8229600" cy="1141413"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody>
</p:sp>
<p:sp>
  <p:nvSpPr><p:cNvPr id="3" name="Body Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="457200" y="1600200"/><a:ext cx="8229600" cy="4525963"/></a:xfrm></p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody>
</p:sp>
</p:spTree></p:cSld>
<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>
<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>
</p:sldMaster>';
    }

    private static function shapeRect(string $name, int $x, int $y, int $w, int $h, string $color, int $id): string
    {
        return '<p:sp>
  <p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>
    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
    <a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln>
  </p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody>
</p:sp>';
    }

    private static function shapeEllipse(string $name, int $x, int $y, int $w, int $h, string $color, int $id): string
    {
        return '<p:sp>
  <p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>
    <a:prstGeom prst="ellipse"><a:avLst/></a:prstGeom>
    <a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln>
  </p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody>
</p:sp>';
    }

    private static function shapeTriangle(string $name, int $x, int $y, int $w, int $h, string $color, int $id): string
    {
        return '<p:sp>
  <p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>
    <a:prstGeom prst="rtTriangle"><a:avLst/></a:prstGeom>
    <a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln>
  </p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody>
</p:sp>';
    }

    /**
     * Butun slayd chetlab yupqa rangli ramka — to'ldirilmagan, faqat konturi.
     */
    private static function shapeFrame(string $color, int $id): string
    {
        return '<p:sp>
  <p:nvSpPr><p:cNvPr id="' . $id . '" name="Frame"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>
  <p:spPr><a:xfrm><a:off x="91440" y="91440"/><a:ext cx="8961120" cy="6675120"/></a:xfrm>
    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
    <a:noFill/><a:ln w="57150"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln>
  </p:spPr>
  <p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody>
</p:sp>';
    }

    private static function slideMasterRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
</Relationships>';
    }

    private static function slideLayoutXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="title" preserve="1">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody></p:sp>
<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody></p:sp>
</p:spTree></p:cSld>
</p:sldLayout>';
    }

    private static function slideLayoutRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
</Relationships>';
    }

    private static function themeXml(array $design): string
    {
        $accent = $design['accent'];
        $title = $design['title'];
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="StudyBot">
<a:themeElements>
<a:clrScheme name="StudyBot">
<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>
<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>
<a:dk2><a:srgbClr val="' . $title . '"/></a:dk2>
<a:lt2><a:srgbClr val="EEECE1"/></a:lt2>
<a:accent1><a:srgbClr val="' . $accent . '"/></a:accent1>
<a:accent2><a:srgbClr val="ED7D31"/></a:accent2>
<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3>
<a:accent4><a:srgbClr val="FFC000"/></a:accent4>
<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5>
<a:accent6><a:srgbClr val="70AD47"/></a:accent6>
<a:hlink><a:srgbClr val="0563C1"/></a:hlink>
<a:folHlink><a:srgbClr val="954F72"/></a:folHlink>
</a:clrScheme>
<a:fontScheme name="StudyBot"><a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>
<a:fmtScheme name="StudyBot"><a:fillStyleLst><a:solidFill><a:schemeClr val="accent1"/></a:solidFill><a:solidFill><a:schemeClr val="accent1"/></a:solidFill><a:solidFill><a:schemeClr val="accent1"/></a:solidFill></a:fillStyleLst>
<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:ln><a:ln w="12700"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:ln><a:ln w="19050"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:ln></a:lnStyleLst>
<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>
<a:bgFillStyleLst><a:solidFill><a:schemeClr val="lt1"/></a:solidFill><a:solidFill><a:schemeClr val="lt1"/></a:solidFill><a:solidFill><a:schemeClr val="lt1"/></a:solidFill></a:bgFillStyleLst>
</a:fmtScheme>
</a:themeElements>
</a:theme>';
    }

    private static function contentTypes(int $count, array $notesIdx = []): string
    {
        $overrides = '';
        for ($i = 1; $i <= $count; $i++) {
            $overrides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        if ($notesIdx) {
            $overrides .= '<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>'
                . '<Override PartName="/ppt/theme/theme2.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>';
            foreach ($notesIdx as $i) {
                $overrides .= '<Override PartName="/ppt/notesSlides/notesSlide' . (int)$i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Default Extension="jpg" ContentType="image/jpeg"/>
<Default Extension="jpeg" ContentType="image/jpeg"/>
<Default Extension="png" ContentType="image/png"/>
<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>
<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>
<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>
' . $overrides . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>';
    }
}
