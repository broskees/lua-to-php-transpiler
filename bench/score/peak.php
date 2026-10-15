<?php

// Prepended (auto_prepend_file) to every benchmark run of bench/score/score.php:
// at shutdown, the run's peak PHP memory goes to the file LUAPHP_SCORE_PEAK_FILE names.

declare(strict_types=1);

register_shutdown_function(static function (): void {
    $peakFile = getenv('LUAPHP_SCORE_PEAK_FILE');
    if (is_string($peakFile) && $peakFile !== '') {
        file_put_contents($peakFile, (string) memory_get_peak_usage());
    }
});
