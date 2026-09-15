<?php

namespace App\Core\Logging;

use App\Core\Database\QueryLog;
use Tracy\IBarPanel;

/**
 * Tracy bar panel listing the SQL from QueryLog.
 *
 * A statement run more than once is flagged with its count, because the same
 * SELECT repeated inside a loop (N+1) is the most common reason a page is slow
 * and the hardest to notice from the code.
 */
final class QueryPanel implements IBarPanel {
    public function getTab(): string {
        $failed = count(array_filter(QueryLog::queries(), static fn (array $q): bool => $q['error'] !== null));

        return '<span title="SQL queries">'
            . ($failed > 0 ? '<span style="color:#c00">SQL ' : 'SQL ')
            . QueryLog::count() . ' / ' . self::ms(QueryLog::totalMs())
            . ($failed > 0 ? '</span>' : '')
            . '</span>';
    }

    public function getPanel(): string {
        $queries = QueryLog::queries();

        if ($queries === []) {
            return '<h1>SQL</h1><div class="tracy-inner"><p>No queries.</p></div>';
        }

        $repeats = array_count_values(array_column($queries, 'sql'));

        $html = '<h1>SQL: ' . QueryLog::count() . ' queries, ' . self::ms(QueryLog::totalMs()) . '</h1>'
            . '<div class="tracy-inner"><table>'
            . '<tr><th>ms</th><th>Query</th><th>Bindings</th></tr>';

        foreach ($queries as $query) {
            $times = $repeats[$query['sql']];

            $html .= '<tr><td>' . number_format($query['ms'], 2) . '</td><td>'
                . '<code>' . self::esc($query['sql']) . '</code>'
                . ($times > 1 ? ' <strong style="color:#c60">×' . $times . '</strong>' : '')
                . ($query['error'] !== null ? '<br><span style="color:#c00">' . self::esc($query['error']) . '</span>' : '')
                . '</td><td><code>' . self::esc(self::bindings($query['bindings'])) . '</code></td></tr>';
        }

        $html .= '</table>';

        if (QueryLog::count() > count($queries)) {
            $html .= '<p>Only the first ' . count($queries) . ' are listed.</p>';
        }

        return $html . '</div>';
    }

    private static function bindings(array $bindings): string {
        if ($bindings === []) {
            return '';
        }

        return (string) json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function ms(float $ms): string {
        return number_format($ms, 1) . ' ms';
    }

    private static function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
