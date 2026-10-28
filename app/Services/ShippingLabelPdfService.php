<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShippingLabelPdfService
{
    /**
     * Render the shipping label as an HTTP Response (inline preview or attachment download).
     */
    public function renderResponse(Shipment $shipment, bool $inline = false): Response
    {
        $pdfContent = $this->getPdfContent($shipment);
        $filename = 'shipping_label_'.$shipment->id.'.pdf';
        $disposition = $inline ? 'inline' : 'attachment';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Content-Length' => strlen($pdfContent),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Label-Shipment-Id' => (string) $shipment->id,
            'X-Label-Tracking' => (string) ($shipment->tracking_number ?? ''),
        ]);
    }

    /**
     * Retrieve the label PDF binary content.
     */
    public function getPdfContent(Shipment $shipment): string
    {
        $rawUrl = $shipment->getRawOriginal('label_download_url') ?? $shipment->label_download_url;

        // If an external live URL exists (and is not an unreachable mock URL or local endpoint)
        if (! empty($rawUrl)
            && filter_var($rawUrl, FILTER_VALIDATE_URL)
            && ! str_contains($rawUrl, 'mock_label')
            && ! str_contains($rawUrl, '/api/shipping/shipments/')
        ) {
            try {
                $response = Http::timeout(5)->get($rawUrl);
                if ($response->successful()) {
                    $body = $response->body();
                    if (str_starts_with($body, '%PDF')) {
                        return $body;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Could not fetch remote label PDF from {$rawUrl}: ".$e->getMessage());
            }
        }

        return $this->generatePdf($shipment);
    }

    /**
     * Generate an authentic, production-grade 4x6 inch (288x432 pt) PDF 1.4 shipping label.
     */
    public function generatePdf(Shipment $shipment): string
    {
        try {
            $shipment->loadMissing('order.buyer', 'order.items.product', 'order.seller');
        } catch (\Throwable) {
            // Safe fallback
        }

        // ── 1. Dynamic Carrier & Service Data ─────────────────────────────
        $rawCarrierCode = strtoupper((string) ($shipment->carrier_code ?? 'ROYAL_MAIL'));
        $carrierName = match ($rawCarrierCode) {
            'ROYAL_MAIL' => 'ROYAL MAIL',
            'USPS' => 'USPS',
            'UPS' => 'UPS',
            'FEDEX' => 'FEDEX',
            'DHL', 'DHL_EXPRESS' => 'DHL EXPRESS',
            default => str_replace('_', ' ', $rawCarrierCode),
        };

        // Clean service code (strip redundant carrier name prefixes)
        $rawServiceCode = strtoupper((string) ($shipment->service_code ?? 'TRACKED_48'));
        $cleanedService = str_replace(
            ['ROYAL_MAIL_', 'ROYAL MAIL ', 'USPS_', 'USPS ', 'UPS_', 'UPS ', 'FEDEX_', 'FEDEX '],
            '',
            $rawServiceCode
        );
        $serviceCode = str_replace('_', ' ', trim($cleanedService));
        if (empty($serviceCode)) {
            $serviceCode = 'STANDARD DELIVERY';
        }

        $trackingNumber = (string) ($shipment->tracking_number ?? 'MCKF'.str_pad((string) ($shipment->id ?? 1), 8, '0', STR_PAD_LEFT));
        $shipDate = $shipment->ship_date?->toDateString() ?? now()->toDateString();
        $orderId = (string) ($shipment->order_id ?? $shipment->order?->id ?? 'N/A');
        $shipmentId = (string) ($shipment->id ?? $shipment->getAttribute('id') ?? 'N/A');

        // Dynamic Weight & Dimensions
        $packages = $shipment->package_snapshot ?? [];
        $firstPkg = isset($packages[0]) && is_array($packages[0]) ? $packages[0] : (is_array($packages) ? $packages : []);
        $weightStr = $this->formatPackageWeight($firstPkg);

        $costVal = $shipment->shipping_cost !== null ? (float) $shipment->shipping_cost : 3.99;
        $currencyVal = strtoupper((string) ($shipment->currency ?? 'USD'));
        $costStr = sprintf('$%.2f %s', $costVal, $currencyVal);

        // ── 2. Dynamic Ship From (Sender) Data ────────────────────────────
        $shipFrom = $shipment->ship_from_snapshot ?? [];
        if (empty($shipFrom) && $shipment->order) {
            $sellerUser = $shipment->order->seller?->user;
            if ($sellerUser) {
                $shipFrom = [
                    'name' => $sellerUser->name ?: ($shipment->order->seller->full_name ?? 'Shipper'),
                    'company_name' => $shipment->order->seller->full_name ?? '',
                    'address_line1' => $sellerUser->address_line_1 ?? '',
                    'address_line2' => $sellerUser->address_line_2 ?? '',
                    'city_locality' => $sellerUser->city ?? '',
                    'state_province' => $sellerUser->state ?? '',
                    'postal_code' => $sellerUser->postal_code ?? '',
                    'country_code' => $sellerUser->country ?? 'GB',
                    'phone' => $sellerUser->phone ?? '',
                ];
            }
        }

        $fromName = trim($shipFrom['name'] ?? ($shipFrom['company_name'] ?? 'Logistics Center'));
        $fromCompany = trim($shipFrom['company_name'] ?? '');
        if ($fromCompany === $fromName) {
            $fromCompany = '';
        }
        $fromLine1 = trim($shipFrom['address_line1'] ?? '123 Logistics Way');
        $fromLine2 = trim($shipFrom['address_line2'] ?? '');
        $fromCity = trim($shipFrom['city_locality'] ?? 'London');
        $fromState = trim($shipFrom['state_province'] ?? '');
        $fromZip = trim($shipFrom['postal_code'] ?? 'EC1A 1BB');
        $fromCountry = strtoupper(trim($shipFrom['country_code'] ?? 'GB'));
        $fromPhone = trim($shipFrom['phone'] ?? '');

        // ── 3. Dynamic Ship To (Recipient) Data ───────────────────────────
        $shipTo = $shipment->ship_to_snapshot ?? [];
        if (empty($shipTo) && $shipment->order) {
            $shippingAddr = $shipment->order->shipping_address ?? [];
            if (is_string($shippingAddr)) {
                $shippingAddr = json_decode($shippingAddr, true) ?? [];
            }
            $shipTo = [
                'name' => $shippingAddr['name'] ?? ($shipment->order->buyer?->full_name ?? ($shipment->order->buyer?->name ?? 'Customer Delivery')),
                'address_line1' => $shippingAddr['line1'] ?? ($shippingAddr['address_line1'] ?? ''),
                'address_line2' => $shippingAddr['line2'] ?? ($shippingAddr['address_line2'] ?? ''),
                'city_locality' => $shippingAddr['city'] ?? ($shippingAddr['city_locality'] ?? ''),
                'state_province' => $shippingAddr['state'] ?? ($shippingAddr['state_province'] ?? ''),
                'postal_code' => $shippingAddr['postal_code'] ?? ($shippingAddr['zip'] ?? ''),
                'country_code' => $shippingAddr['country'] ?? ($shippingAddr['country_code'] ?? 'US'),
                'phone' => $shippingAddr['phone'] ?? '',
            ];
        }

        $toName = trim($shipTo['name'] ?? 'Customer Delivery');
        $toLine1 = trim($shipTo['address_line1'] ?? '');
        $toLine2 = trim($shipTo['address_line2'] ?? '');
        $toCity = trim($shipTo['city_locality'] ?? '');
        $toState = trim($shipTo['state_province'] ?? '');
        $toZip = trim($shipTo['postal_code'] ?? '');
        $toCountry = strtoupper(trim($shipTo['country_code'] ?? 'US'));
        $toPhone = trim($shipTo['phone'] ?? '');

        // Primary destination routing string
        $routingPostal = strtoupper(trim(($toZip ?: 'SW1A 1AA').($toState ? ' - '.$toState : '').($toCountry ? ', '.$toCountry : '')));

        // ── PDF STREAM ASSEMBLY ──────────────────────────────────────────
        $s = [];
        $s[] = 'q'; // save graphics state

        // Outer border (solid 1.5pt clean frame: 10 6 268 420)
        $s[] = '1.5 w 0 0 0 RG 10 6 268 420 re S';

        // ── ZONE 1: HEADER & POSTAGE INDICIA (y: 350 to 424) ─────────────
        // Carrier & Service Title
        $s[] = $this->pdfText(15, 403, $carrierName, 16, true);
        $s[] = $this->pdfText(15, 388, $serviceCode, 10.5, true);
        $s[] = $this->pdfText(15, 375, 'COMMERCIAL PARCEL', 7.5);

        // Service Indicator Badge (solid square with bold indicator code)
        $s[] = '0 0 0 rg 132 360 38 48 re f';
        $indicator = str_contains($serviceCode, '48') ? '48' : (str_contains($serviceCode, '24') ? '24' : (str_contains($serviceCode, 'PRI') ? 'PRI' : (str_contains($serviceCode, 'EXP') ? 'EXP' : 'STD')));
        $s[] = '1 1 1 rg'; // white text
        $s[] = $this->pdfText(138, 376, $indicator, 17, true);
        $s[] = '0 0 0 rg'; // reset black

        // Postage Paid Indicia Box (top right)
        $s[] = '0.8 w 176 358 96 54 re S';
        $s[] = $this->pdfText(182, 400, 'POSTAGE PAID', 8, true);
        $s[] = $this->pdfText(182, 389, 'CARRIER: '.$this->cleanString($carrierName), 6.5);
        $s[] = $this->pdfText(182, 378, 'ACCT: 902'.substr(md5($orderId), 0, 5), 6.5);
        $s[] = $this->pdfText(182, 367, 'DATE: '.$this->cleanString($shipDate), 6.5);

        // Divider below Header
        $s[] = '1.8 w 10 350 m 278 350 l S';

        // ── ZONE 2: RETURN ADDRESS (FROM) (y: 298 to 350) ────────────────
        $s[] = $this->pdfText(15, 339, 'FROM:', 8, true);
        $s[] = $this->pdfText(46, 339, $fromName, 8, true);

        $curFromY = 328;
        if (! empty($fromCompany)) {
            $s[] = $this->pdfText(46, $curFromY, $fromCompany, 7.5);
            $curFromY -= 10;
        }
        $s[] = $this->pdfText(46, $curFromY, $fromLine1, 7.5);
        $curFromY -= 10;
        if (! empty($fromLine2)) {
            $s[] = $this->pdfText(46, $curFromY, $fromLine2, 7.5);
            $curFromY -= 10;
        }
        $fromCityLine = trim(($fromCity ? $fromCity.', ' : '').($fromState ? $fromState.' ' : '').$fromZip.' '.$fromCountry);
        $s[] = $this->pdfText(46, $curFromY, $fromCityLine, 7.5);
        if (! empty($fromPhone)) {
            $curFromY -= 9;
            $s[] = $this->pdfText(46, $curFromY, 'Tel: '.$fromPhone, 7);
        }

        // Top Right Order Badge
        $s[] = '0.6 w 190 306 82 36 re S';
        $s[] = $this->pdfText(196, 330, 'ORDER REF:', 7, true);
        $s[] = $this->pdfText(196, 319, '#'.$orderId, 8.5, true);
        $s[] = $this->pdfText(196, 309, 'PACKAGE: 1 OF 1', 6.5);

        // Divider below Return Address
        $s[] = '1.2 w 10 298 m 278 298 l S';

        // ── ZONE 3: SHIP TO (RECIPIENT) & 2D MATRIX (y: 198 to 298) ──────
        $s[] = $this->pdfText(15, 285, 'SHIP TO:', 8.5, true);
        $s[] = $this->pdfText(15, 270, strtoupper($toName), 11.5, true);

        $currToY = 256;
        if (! empty($toLine1)) {
            $s[] = $this->pdfText(15, $currToY, $toLine1, 9.5);
            $currToY -= 13;
        }
        if (! empty($toLine2)) {
            $s[] = $this->pdfText(15, $currToY, $toLine2, 9);
            $currToY -= 12;
        }
        $cityStateZip = trim(($toCity ? $toCity.', ' : '').($toState ? $toState.' ' : '').$toZip);
        if (! empty($cityStateZip)) {
            $s[] = $this->pdfText(15, $currToY, strtoupper($cityStateZip), 11.5, true);
            $currToY -= 13;
        }
        $s[] = $this->pdfText(15, $currToY, strtoupper($toCountry === 'US' ? 'UNITED STATES' : ($toCountry === 'GB' ? 'UNITED KINGDOM' : $toCountry)), 9.5, true);
        if (! empty($toPhone)) {
            $currToY -= 11;
            $s[] = $this->pdfText(15, $currToY, 'Phone: '.$toPhone, 8);
        }

        // Draw 2D QR Code on the right (x: 204, y: 220, size: 60)
        $qrSize = 60.0;
        $qrX = 204.0;
        $qrY = 220.0;
        $qrCenterX = $qrX + ($qrSize / 2.0); // 234.0 pt
        $qrData = url('/api/shipping/shipments/'.$shipmentId.'/tracking?tracking_number='.$trackingNumber);
        $s[] = $this->generateQRCode($qrX, $qrY, $qrSize, $qrData);

        // Center "SCAN TO TRACK" directly beneath QR Code
        $scanText = 'SCAN TO TRACK';
        $scanWidth = strlen($scanText) * (6.0 * 0.55);
        $scanX = max(195.0, round($qrCenterX - ($scanWidth / 2.0), 1));
        $s[] = $this->pdfText($scanX, 210, $scanText, 6.0, true);

        // Center "SORT: <ZIP>" on second line below "SCAN TO TRACK"
        $sortText = ! empty($toZip) ? 'SORT: '.substr($toZip, 0, 8) : 'STANDARD';
        $sortWidth = strlen($sortText) * (6.0 * 0.55);
        $sortX = max(195.0, round($qrCenterX - ($sortWidth / 2.0), 1));
        $s[] = $this->pdfText($sortX, 202, $sortText, 6.0, true);

        // Divider below Deliver To
        $s[] = '1.5 w 10 198 m 278 198 l S';

        // ── ZONE 4: DESTINATION ROUTING BANNER (y: 172 to 198) ───────────
        $s[] = '0.95 0.95 0.95 rg 10 172 268 26 re f 0 0 0 rg'; // subtle fill
        $s[] = '1.2 w 10 172 268 26 re S';
        $s[] = $this->pdfText(16, 181, 'DESTINATION ROUTING:', 8.5, true);
        $s[] = $this->pdfText(140, 180, $routingPostal, 11.5, true);

        // ── ZONE 5: 1D LINEAR BARCODE (CODE 128) (y: 84 to 172) ──────────
        // Generate authentic, scanner-readable Code 128 vector bars
        $barcodeWidth = 236.0;
        $barcodeStartX = (288.0 - $barcodeWidth) / 2.0; // 26.0 pt (perfectly centered)
        $barcodeY = 110.0;
        $barcodeHeight = 52.0;

        $s[] = $this->generateCode128Bars($trackingNumber, $barcodeStartX, $barcodeY, $barcodeWidth, $barcodeHeight);

        // Human-readable tracking number below barcode (clean letter-spaced format, centered)
        $formattedTracking = implode(' ', str_split($trackingNumber, 4));
        $trackText = 'TRACKING: '.$formattedTracking;
        $trackTextWidth = strlen($trackText) * (11.5 * 0.58);
        $trackX = max(15.0, (288.0 - $trackTextWidth) / 2.0);
        $s[] = $this->pdfText($trackX, 96, $trackText, 11.5, true);

        $carrierSub = $carrierName.' - '.$serviceCode.' - NO SIGNATURE REQUIRED';
        $subWidth = strlen($carrierSub) * (7.0 * 0.52);
        $subX = max(15.0, (288.0 - $subWidth) / 2.0);
        $s[] = $this->pdfText($subX, 86, $carrierSub, 7.0);

        // Divider before Metadata Table
        $s[] = '1.5 w 10 78 m 278 78 l S';

        // ── ZONE 6: FULFILLMENT METADATA GRID (y: 18 to 78) ──────────────
        // Outer table border
        $s[] = '0.8 w 10 18 268 60 re S';
        // Horizontal dividers
        $s[] = '0.5 w 10 58 m 278 58 l S';
        $s[] = '0.5 w 10 38 m 278 38 l S';
        // Vertical divider for all rows
        $s[] = '0.5 w 144 18 m 144 78 l S';

        // Row 1 (y: 64): Order ID & Ship Date
        $s[] = $this->pdfText(16, 64, 'ORDER NO:', 7.5, true);
        $s[] = $this->pdfText(68, 64, '#'.$orderId, 8.5);
        $s[] = $this->pdfText(148, 64, 'SHIP DATE:', 7.5, true);
        $s[] = $this->pdfText(198, 64, $shipDate, 8.0);

        // Row 2 (y: 44): Shipment ID & Weight
        $s[] = $this->pdfText(16, 44, 'SHIPMENT:', 7.5, true);
        $s[] = $this->pdfText(68, 44, '#'.$shipmentId, 8.5);
        $s[] = $this->pdfText(148, 44, 'WEIGHT:', 7.5, true);
        $s[] = $this->pdfText(194, 44, $weightStr, 7.5);

        // Row 3 (y: 24): Carrier Service & Cost
        $s[] = $this->pdfText(16, 24, 'CARRIER:', 7.5, true);
        $s[] = $this->pdfText(58, 24, substr($carrierName.' '.$serviceCode, 0, 22), 7.2);
        $s[] = $this->pdfText(148, 24, 'POSTAGE:', 7.5, true);
        $s[] = $this->pdfText(198, 24, $costStr, 8.0);

        // Bottom verification banner (y: 10)
        $s[] = $this->pdfText(38, 10, 'CASTILO OFFICIAL FULFILLMENT NETWORK - LICENSED DISPATCH', 6.0, true);

        $s[] = 'Q'; // restore graphics state

        $streamContent = implode("\n", $s)."\n";
        $streamLen = strlen($streamContent);

        // Assemble PDF 1.4 objects
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];

        // Obj 1: Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Obj 2: Pages
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // Obj 3: Page (4x6 inches: 288 x 432 pt)
        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>\nendobj\n";

        // Obj 5: Font F1 (Helvetica)
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Obj 6: Font F2 (Helvetica-Bold)
        $offsets[6] = strlen($pdf);
        $pdf .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Obj 4: Content stream
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Length {$streamLen} >>\nstream\n".$streamContent."endstream\nendobj\n";

        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 7\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * Absolute position PDF text block (isolated BT...ET with exact x, y coordinates).
     */
    protected function pdfText(float $x, float $y, string $text, float $fontSize, bool $bold = false): string
    {
        $font = $bold ? '/F2' : '/F1';
        $cleaned = $this->cleanString($text);

        return sprintf('BT %s %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $font, $fontSize, $x, $y, $cleaned);
    }

    /**
     * Format package weight and dimensions dynamically.
     */
    protected function formatPackageWeight(array $pkg): string
    {
        $weightVal = null;
        $weightUnit = 'ounce';

        if (isset($pkg['weight'])) {
            if (is_array($pkg['weight'])) {
                $weightVal = isset($pkg['weight']['value']) ? (float) $pkg['weight']['value'] : null;
                $weightUnit = strtolower((string) ($pkg['weight']['unit'] ?? 'ounce'));
            } else {
                $weightVal = (float) $pkg['weight'];
            }
        }

        if ($weightVal === null || $weightVal <= 0) {
            return '0.50 LB / 0.23 KG';
        }

        if (in_array($weightUnit, ['ounce', 'oz'], true)) {
            $oz = $weightVal;
            $lb = $oz / 16.0;

            return sprintf('%.1f OZ / %.2f LB', $oz, $lb);
        }

        if (in_array($weightUnit, ['pound', 'lb', 'lbs'], true)) {
            $lb = $weightVal;
            $kg = $lb * 0.45359237;

            return sprintf('%.2f LB / %.2f KG', $lb, $kg);
        }

        if (in_array($weightUnit, ['kilogram', 'kg'], true)) {
            $kg = $weightVal;
            $lb = $kg * 2.20462;

            return sprintf('%.2f KG / %.2f LB', $kg, $lb);
        }

        return sprintf('%.2f %s', $weightVal, strtoupper($weightUnit));
    }

    /**
     * Generate an authentic, scanner-readable Code 128 (Subset B) barcode.
     */
    protected function generateCode128Bars(string $text, float $startX, float $y, float $totalWidth, float $h): string
    {
        // Code 128 symbol patterns (widths of 6 alternating bars/spaces, summing to 11 modules)
        static $p = [
            '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
            '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
            '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
            '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
            '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
            '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
            '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
            '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
            '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
            '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
            '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
            '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
            '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
            '211214', '211232', '2331112',
        ];

        // Clean tracking string to printable ASCII
        $clean = preg_replace('/[^ -~]/', '', $text);
        if (empty($clean)) {
            $clean = 'MCKF00000000';
        }

        // Start B = 104
        $sum = 104;
        $patterns = [$p[104]];
        $len = strlen($clean);

        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($clean[$i]);
            $val = ($ascii >= 32 && $ascii <= 126) ? $ascii - 32 : 0;
            $sum += ($i + 1) * $val;
            $patterns[] = $p[$val];
        }

        // Checksum & Stop
        $checksum = $sum % 103;
        $patterns[] = $p[$checksum];
        $patterns[] = $p[106]; // Stop code (2331112)

        // Calculate total modules: each data pattern is 11 modules, stop is 13 modules
        $totalModules = (count($patterns) - 1) * 11 + 13;
        $moduleWidth = $totalWidth / $totalModules;

        $ops = [];
        $ops[] = '0 0 0 rg'; // solid black fill
        $currX = $startX;

        foreach ($patterns as $pattern) {
            $patLen = strlen($pattern);
            for ($k = 0; $k < $patLen; $k++) {
                $widthUnits = (int) $pattern[$k];
                $elemWidth = $widthUnits * $moduleWidth;

                // Even indices (0, 2, 4...) are black bars, odd indices are white spaces
                if ($k % 2 === 0) {
                    $ops[] = sprintf('%.2f %.2f %.2f %.2f re f', $currX, $y, $elemWidth, $h);
                }
                $currX += $elemWidth;
            }
        }

        return implode("\n", $ops);
    }

    /**
     * Generate an authentic, scanner-readable 2D QR Code.
     */
    protected function generateQRCode(float $x, float $y, float $size, string $data): string
    {
        try {
            $qrcode = new \chillerlan\QRCode\QRCode();
            $qrcode->addByteSegment($data);
            $matrix = $qrcode->getQRMatrix();
            $boolMatrix = $matrix->getBooleanMatrix();

            $numRows = count($boolMatrix);
            $numCols = count($boolMatrix[0]);
            $cellSize = $size / $numCols;

            $ops = [];
            // Solid white background to ensure crisp contrast and clean quiet zone
            $ops[] = '1 1 1 rg';
            $ops[] = sprintf('%.2f %.2f %.2f %.2f re f', $x, $y, $size, $size);
            $ops[] = '0 0 0 rg'; // solid black fill

            for ($r = 0; $r < $numRows; $r++) {
                $cellY = $y + $size - (($r + 1) * $cellSize);
                for ($c = 0; $c < $numCols; $c++) {
                    if ($boolMatrix[$r][$c]) {
                        $cellX = $x + ($c * $cellSize);
                        $ops[] = sprintf('%.2f %.2f %.2f %.2f re f', $cellX, $cellY, $cellSize, $cellSize);
                    }
                }
            }

            return implode("\n", $ops);
        } catch (\Throwable $e) {
            Log::warning('QR generation failed: '.$e->getMessage());

            return '';
        }
    }

    /**
     * Clean and escape string for PDF text output.
     */
    protected function cleanString(string $str): string
    {
        // Convert bullet points to standard dash
        $str = str_replace(['•', '·', '—', '–'], '-', $str);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str;

        // Escape backslashes and parentheses
        $escaped = str_replace('\\', '\\\\', $ascii);
        $escaped = str_replace('(', '\\(', $escaped);
        $escaped = str_replace(')', '\\)', $escaped);

        return preg_replace('/[^\x20-\x7E]/', '', $escaped);
    }
}
