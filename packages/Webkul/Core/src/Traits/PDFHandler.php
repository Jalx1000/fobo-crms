<?php

namespace Webkul\Core\Traits;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Mpdf\Mpdf;

trait PDFHandler
{
    /**
     * Download PDF.
     *
     * @return \Illuminate\Http\Response
     */
    protected function downloadPDF(string $html, ?string $fileName = null)
    {
        if (is_null($fileName)) {
            $fileName = Str::random(32);
        }

        $contents = $this->generatePDF($html);

        return response()->streamDownload(fn () => print ($contents), $fileName.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Render the same PDF as downloadPDF(), but return the raw bytes instead of
     * a response.
     *
     * Needed by anything that has to hand the file to somewhere other than the
     * browser — attaching a quote to a WhatsApp message, mailing it, archiving
     * it. Kept here so there is a single definition of "what our PDFs look
     * like": the RTL branch, the paper size and the font all stay in one place.
     */
    protected function generatePDF(string $html): string
    {
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        if (in_array($direction = app()->getLocale(), ['ar', 'he'])) {
            $mPDF = new Mpdf([
                'margin_left'  => 0,
                'margin_right' => 0,
                'margin_top'   => 0,
                'margin_bottom'=> 0,
            ]);

            $mPDF->SetDirectionality($direction);

            $mPDF->SetDisplayMode('fullpage');

            $mPDF->WriteHTML($this->adjustArabicAndPersianContent($html));

            return (string) $mPDF->Output('', 'S');
        }

        return (string) PDF::loadHTML($this->adjustArabicAndPersianContent($html))
            ->setPaper('A4', 'portrait')
            ->set_option('defaultFont', 'Courier')
            ->output();
    }

    /**
     * Adjust arabic and persian content.
     *
     * @return string
     */
    protected function adjustArabicAndPersianContent(string $html)
    {
        $arabic = new \ArPHP\I18N\Arabic;

        $p = $arabic->arIdentify($html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $html = substr_replace($html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        return $html;
    }
}
