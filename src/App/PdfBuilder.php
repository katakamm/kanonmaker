<?php

declare(strict_types=1);

namespace Kanon\App;

/**
 * Builds the print HTML for the exported list.
 *
 * Kept separate from mPDF so the part that can be wrong - what appears on the
 * page - is testable without rendering a PDF.
 */
final class PdfBuilder
{
    public static function html(array $options): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $out = '<html><head><meta charset="utf-8"><style>'
             . 'body{font-family:dejavusans,sans-serif;font-size:11pt;color:#000}'
             . 'h1{font-size:15pt;margin:0 0 2mm}h2{font-size:11pt;margin:6mm 0 2mm;border-bottom:.3mm solid #999}'
             . '.meta{font-size:10pt;color:#444;margin:0 0 6mm}'
             . 'table{width:100%;border-collapse:collapse}'
             . 'td{vertical-align:top;padding:1.2mm 0;border-bottom:.2mm solid #ddd}'
             . 'td.n{width:8mm;color:#555}'
             . '.a{font-size:10pt;color:#333}.t{font-size:11pt}'
             . '.tags{font-size:8.5pt;color:#555}'
             . '.rules{margin-top:8mm;font-size:9.5pt}'
             . '.rules td{border:0;padding:.6mm 0}'
             . '</style></head><body>';

        $out .= '<h1>Seznam četby k maturitní zkoušce</h1>';

        $meta = array_filter([
            $options['name'] !== '' ? $e($options['name']) : null,
            $options['year'] !== '' ? 'školní rok ' . $e($options['year']) : null,
        ]);
        if ($meta !== []) {
            $out .= '<p class="meta">' . implode(' &middot; ', $meta) . '</p>';
        }

        if ($options['works'] === []) {
            return $out . '<p>Seznam je prázdný.</p></body></html>';
        }

        $groups = $options['grouped']
            ? self::byChapter($options['works'])
            : ['' => $options['works']];

        $number = 1;
        foreach ($groups as $heading => $works) {
            if ($heading !== '') {
                $out .= '<h2>' . $e($heading) . '</h2>';
            }

            $out .= '<table>';
            foreach ($works as $work) {
                $out .= '<tr><td class="n">' . $number++ . '.</td><td>';

                if ($work['authors'] !== '') {
                    $out .= '<div class="a">' . $e($work['authors']) . '</div>';
                }
                $out .= '<div class="t">' . $e($work['title']) . '</div>';

                if ($options['chips']) {
                    $labels = array_map(
                        static fn (array $t): string => $t['label'],
                        Ui::orderedTags($work['tags'])
                    );
                    if ($labels !== []) {
                        $out .= '<div class="tags">' . $e(implode(' · ', $labels)) . '</div>';
                    }
                }

                $out .= '</td></tr>';
            }
            $out .= '</table>';
        }

        if ($options['rules']) {
            $out .= '<div class="rules"><h2>Kontrola pravidel</h2><table>';
            foreach ($options['results'] as $result) {
                $out .= '<tr><td>' . ($result->satisfied ? '&#10003;' : '&bull;') . '</td>'
                      . '<td>' . $e($result->label) . '</td>'
                      . '<td style="text-align:right">' . $e($result->current) . '/' . $e($result->required) . '</td></tr>';
            }
            $out .= '</table></div>';
        }

        return $out . '</body></html>';
    }

    /**
     * @param  list<array> $works
     * @return array<string, list<array>>
     */
    private static function byChapter(array $works): array
    {
        $groups = [];
        foreach ($works as $work) {
            $groups[$work['chapter']][] = $work;
        }

        return $groups;
    }
}
