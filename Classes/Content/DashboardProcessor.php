<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Content;

use Featvalue\Typo3\Embed\DashboardRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class DashboardProcessor implements DataProcessorInterface
{
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $height = 'min(800px, 80vh)';
        $flex = (string) ($processedData['data']['pi_flexform'] ?? '');
        if ($flex !== '') {
            $parsed = GeneralUtility::xml2array($flex);
            if (is_array($parsed)) {
                $value = $parsed['data']['sDEF']['lDEF']['settings.height']['vDEF'] ?? '';
                if (is_string($value) && $value !== '') {
                    $height = $value;
                }
            }
        }
        $processedData['featvalueHtml'] = DashboardRenderer::render($height, 'frontend');

        return $processedData;
    }
}
