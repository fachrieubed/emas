<?php

namespace App\Services;

use RuntimeException;

/**
 * Mengambil data harga emas harian secara otomatis dari Yahoo Finance.
 *
 * Simbol default GC=F (Gold Futures - COMEX). Datanya identik dengan data
 * historis emas yang sudah dipakai sistem (uji: OHLC sama persis sampai satuan
 * terkecil), lengkap dengan OHLC + volume sehingga cocok untuk pembaruan
 * otomatis harian sekaligus menambal tanggal yang kosong.
 *
 * Investing.com tidak dapat dipakai otomatis karena melindungi halamannya
 * dengan Cloudflare (HTTP 403), sedangkan Yahoo Finance bisa diakses langsung.
 */
class GoldDataFetcher
{
    public const DEFAULT_SYMBOL = 'GC=F';

    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(private string $symbol = self::DEFAULT_SYMBOL)
    {
    }

    /**
     * Ambil data harian N hari terakhir.
     *
     * @return array<int, array{tanggal:string, pembukaan:float, tertinggi:float, terendah:float, terakhir:float, volume:float}>
     */
    public function fetch(int $days = 90): array
    {
        $rows = $this->requestChart([
            'range' => $this->rangeFor($days),
            'interval' => '1d',
        ]);

        if ($days > 0 && count($rows) > $days) {
            $rows = array_slice($rows, -$days, null, true);
        }

        return array_values($rows);
    }

    /**
     * Ambil seluruh data harian sejak tanggal tertentu (untuk menambal tanggal kosong).
     *
     * @return array<int, array{tanggal:string, pembukaan:float, tertinggi:float, terendah:float, terakhir:float, volume:float}>
     */
    public function fetchSince(string $fromIso): array
    {
        // mundur beberapa hari untuk berjaga-jaga perbedaan zona waktu bursa
        $from = (new \DateTimeImmutable($fromIso, new \DateTimeZone('UTC')))->modify('-5 days');

        return array_values($this->requestChart([
            'period1' => $from->getTimestamp(),
            'period2' => time(),
            'interval' => '1d',
        ]));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>> baris terindeks tanggal (menaik)
     */
    private function requestChart(array $params): array
    {
        $params += ['includePrePost' => 'false', 'events' => 'div,splits'];
        $url = self::CHART_URL . rawurlencode($this->symbol) . '?' . http_build_query($params);

        $payload = json_decode($this->request($url), true);

        if (!is_array($payload) || !isset($payload['chart']['result'][0])) {
            $message = $payload['chart']['error']['description'] ?? 'respons tidak dikenali';
            throw new RuntimeException("Gagal mengambil data dari Yahoo Finance: {$message}.");
        }

        $result = $payload['chart']['result'][0];
        $timezone = new \DateTimeZone($result['meta']['exchangeTimezoneName'] ?? 'America/New_York');
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        // Tanggal hari ini menurut zona waktu bursa. Bar hari berjalan masih
        // parsial (volume belum lengkap) sehingga dilewati.
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');

        $rows = [];
        foreach ($timestamps as $i => $ts) {
            $open = $quote['open'][$i] ?? null;
            $high = $quote['high'][$i] ?? null;
            $low = $quote['low'][$i] ?? null;
            $close = $quote['close'][$i] ?? null;
            $volume = $quote['volume'][$i] ?? null;

            if ($open === null || $high === null || $low === null || $close === null || $volume === null) {
                continue;
            }

            $date = (new \DateTimeImmutable('@' . $ts))->setTimezone($timezone)->format('Y-m-d');

            if ($date >= $today) {
                continue; // bar hari ini (belum selesai)
            }
            if ($this->isWeekend($date)) {
                continue; // Sabtu/Minggu bukan hari perdagangan
            }
            if ((float) $volume <= 0) {
                continue; // volume kosong/nol -> data tidak lengkap
            }

            $rows[$date] = [
                'tanggal' => $date,
                'pembukaan' => round((float) $open, 2),
                'tertinggi' => round((float) $high, 2),
                'terendah' => round((float) $low, 2),
                'terakhir' => round((float) $close, 2),
                'volume' => round((float) $volume),
            ];
        }

        ksort($rows);

        return $rows;
    }

    private function rangeFor(int $days): string
    {
        return match (true) {
            $days <= 5 => '5d',
            $days <= 30 => '1mo',
            $days <= 90 => '3mo',
            $days <= 180 => '6mo',
            default => '1y',
        };
    }

    private function isWeekend(string $isoDate): bool
    {
        // 'N': 1 = Senin ... 6 = Sabtu, 7 = Minggu
        return (int) date('N', strtotime($isoDate)) >= 6;
    }

    private function request(string $url): string
    {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept: application/json,text/plain,*/*',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                throw new RuntimeException(
                    "Permintaan ke Yahoo Finance gagal (HTTP {$status})" . ($error ? ": {$error}" : '.')
                );
            }

            return (string) $body;
        }

        // Fallback bila ekstensi curl tidak tersedia.
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Tidak dapat menghubungi Yahoo Finance (file_get_contents gagal).');
        }

        return $body;
    }
}
