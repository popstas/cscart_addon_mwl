<?php

namespace Tygh\Addons\MwlXlsx\Import;

use Tygh\Registry;

class MwlImportProfiler
{
    /** @var self|null */
    private static $instance;

    /** @var bool */
    private $enabled = false;

    /** @var float */
    private $importStart = 0.0;

    /** @var array product_id => ['start' => float, 'steps' => [name => float], 'total' => float] */
    private $products = [];

    /** @var int|null */
    private $currentProductId;

    /** @var array step_name => ['total' => float, 'count' => int] */
    private $steps = [];

    /** @var array counter_name => int */
    private $counters = [];

    /** @var array step_name => float (start microtime) */
    private $activeSteps = [];

    /**
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->enabled = !empty($_REQUEST['profile_import']);
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param int $productId
     */
    public function startProduct($productId)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->importStart === 0.0) {
            $this->importStart = microtime(true);
        }

        $this->endProduct();

        $this->currentProductId = $productId;
        $this->products[$productId] = [
            'start' => microtime(true),
            'steps' => [],
            'total' => 0.0,
        ];
    }

    /**
     * @param string $name
     */
    public function stepStart($name)
    {
        if (!$this->enabled) {
            return;
        }
        $this->activeSteps[$name] = microtime(true);
    }

    /**
     * @param string $name
     */
    public function stepEnd($name)
    {
        if (!$this->enabled || !isset($this->activeSteps[$name])) {
            return;
        }

        $elapsed = microtime(true) - $this->activeSteps[$name];
        unset($this->activeSteps[$name]);

        if (!isset($this->steps[$name])) {
            $this->steps[$name] = ['total' => 0.0, 'count' => 0];
        }
        $this->steps[$name]['total'] += $elapsed;
        $this->steps[$name]['count']++;

        if ($this->currentProductId !== null && isset($this->products[$this->currentProductId])) {
            if (!isset($this->products[$this->currentProductId]['steps'][$name])) {
                $this->products[$this->currentProductId]['steps'][$name] = 0.0;
            }
            $this->products[$this->currentProductId]['steps'][$name] += $elapsed;
        }
    }

    /**
     * @param string $name
     */
    public function increment($name)
    {
        if (!$this->enabled) {
            return;
        }
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = 0;
        }
        $this->counters[$name]++;
    }

    public function endProduct()
    {
        if (!$this->enabled || $this->currentProductId === null) {
            return;
        }

        // End any active steps
        foreach (array_keys($this->activeSteps) as $name) {
            $this->stepEnd($name);
        }

        if (isset($this->products[$this->currentProductId])) {
            $this->products[$this->currentProductId]['total'] =
                microtime(true) - $this->products[$this->currentProductId]['start'];
        }

        $this->currentProductId = null;
    }

    /**
     * @return string Report file path
     */
    public function writeReport()
    {
        if (!$this->enabled) {
            return '';
        }

        $totalTime = microtime(true) - $this->importStart;
        $totalProducts = count($this->products);

        $lines = [];
        $lines[] = '=== Import Profile Report ===';
        $lines[] = 'Date: ' . date('Y-m-d H:i:s');
        $lines[] = sprintf('Total products: %d', $totalProducts);
        $lines[] = sprintf('Total time: %.1fs', $totalTime);
        $lines[] = '';

        // Step summary
        $lines[] = '--- Step Summary (total / avg per product / count) ---';
        foreach ($this->steps as $name => $data) {
            $avg = $data['count'] > 0 ? $data['total'] / $data['count'] : 0;
            $lines[] = sprintf(
                '%-20s %7.2fs / %7.4fs / %d',
                $name . ':',
                $data['total'],
                $avg,
                $data['count']
            );
        }
        $lines[] = '';

        // Counters
        $lines[] = '--- Counters ---';
        $counterLabels = [
            'image_exists' => 'skipped - already has image',
            'image_import' => 'imported',
            'no_image' => 'no image in data',
            'image_skipped' => '--skip_images',
        ];
        foreach ($this->counters as $name => $count) {
            $label = isset($counterLabels[$name]) ? " ({$counterLabels[$name]})" : '';
            $lines[] = sprintf('%s: %d%s', $name, $count, $label);
        }
        $lines[] = '';

        // Slowest products (top 20)
        $productsByTime = $this->products;
        uasort($productsByTime, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $lines[] = '--- Slowest Products (top 20) ---';
        $i = 0;
        foreach ($productsByTime as $pid => $data) {
            if (++$i > 20) {
                break;
            }
            $stepParts = [];
            foreach ($data['steps'] as $sName => $sTime) {
                $stepParts[] = sprintf('%s: %.3fs', $sName, $sTime);
            }
            $lines[] = sprintf(
                '#%s: %.3fs (%s)',
                $pid,
                $data['total'],
                implode(', ', $stepParts) ?: 'no steps'
            );
        }

        $report = implode("\n", $lines) . "\n";

        // Write to site's var/files/log/
        $varDir = Registry::get('config.dir.root') . '/var/files/log';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0775, true);
        }
        $filename = 'import_profile_' . date('Ymd_His') . '.log';
        $filepath = $varDir . '/' . $filename;
        file_put_contents($filepath, $report);

        echo "\n" . $report;
        echo "Profile report saved to: {$filepath}\n";

        return $filepath;
    }
}
