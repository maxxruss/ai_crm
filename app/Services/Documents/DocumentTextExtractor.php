<?php

declare(strict_types=1);

namespace App\Services\Documents;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

final class DocumentTextExtractor
{
    private const MAX_DOCX_EXPANDED_BYTES = 50 * 1024 * 1024;

    private const MAX_DOCX_XML_BYTES = 25 * 1024 * 1024;

    private const MAX_DOCX_ENTRIES = 1000;

    public function extract(UploadedFile $file): string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'txt', 'md' => $this->extractPlainText($file),
            'pdf' => $this->extractPdf($file),
            'docx' => $this->extractDocx($file),
            default => throw new DocumentExtractionException('Поддерживаются только файлы TXT, MD, PDF и DOCX.'),
        };
    }

    private function extractPlainText(UploadedFile $file): string
    {
        $text = $file->get();

        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new DocumentExtractionException('Текстовый файл должен быть в кодировке UTF-8.');
        }

        return $text;
    }

    private function extractPdf(UploadedFile $file): string
    {
        try {
            $process = new Process([
                'pdftotext',
                '-layout',
                '-enc',
                'UTF-8',
                '-nopgbrk',
                $file->getRealPath(),
                '-',
            ]);
            $process->setTimeout(30);
            $process->run();
        } catch (Throwable $exception) {
            throw new DocumentExtractionException(
                'Не удалось обработать PDF. Проверьте, что файл не повреждён.',
                previous: $exception,
            );
        }

        if (! $process->isSuccessful()) {
            throw new DocumentExtractionException('Не удалось извлечь текст из PDF. Проверьте, что файл не повреждён.');
        }

        $text = $process->getOutput();
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;

        if (trim($text) === '') {
            throw new DocumentExtractionException(
                'В PDF не найден текстовый слой. Сканированные PDF пока не поддерживаются (OCR не настроен).',
            );
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new DocumentExtractionException('Не удалось извлечь из PDF текст в кодировке UTF-8.');
        }

        return $text;
    }

    private function extractDocx(UploadedFile $file): string
    {
        $archive = new ZipArchive();
        $opened = $archive->open($file->getRealPath());

        if ($opened !== true) {
            throw new DocumentExtractionException('Файл DOCX повреждён или имеет неверный формат.');
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_DOCX_ENTRIES) {
                throw new DocumentExtractionException('DOCX содержит слишком много файлов.');
            }

            $expandedBytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                if ($entry === false) {
                    throw new DocumentExtractionException('Не удалось прочитать структуру DOCX.');
                }

                $expandedBytes += (int) ($entry['size'] ?? 0);
                if ($expandedBytes > self::MAX_DOCX_EXPANDED_BYTES) {
                    throw new DocumentExtractionException('Распакованный размер DOCX превышает допустимый лимит.');
                }
            }

            $documentXml = $archive->getFromName('word/document.xml');
            if (! is_string($documentXml) || strlen($documentXml) > self::MAX_DOCX_XML_BYTES) {
                throw new DocumentExtractionException('В DOCX не найден основной текстовый документ или он слишком велик.');
            }
        } finally {
            $archive->close();
        }

        if (stripos($documentXml, '<!DOCTYPE') !== false || stripos($documentXml, '<!ENTITY') !== false) {
            throw new DocumentExtractionException('DOCX содержит неподдерживаемые XML-конструкции.');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $xml = new DOMDocument();
            $xml->resolveExternals = false;
            $xml->substituteEntities = false;
            if (! $xml->loadXML($documentXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new DocumentExtractionException('Не удалось прочитать текст DOCX.');
            }

            $xpath = new DOMXPath($xml);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = $xpath->query('//w:body//w:p');
            if ($paragraphs === false) {
                throw new DocumentExtractionException('Не удалось прочитать текст DOCX.');
            }

            $textParagraphs = [];
            foreach ($paragraphs as $paragraph) {
                $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph);
                if ($nodes === false) {
                    continue;
                }

                $paragraphText = '';
                foreach ($nodes as $node) {
                    $paragraphText .= match ($node->localName) {
                        'tab' => "\t",
                        'br', 'cr' => "\n",
                        default => $node->textContent,
                    };
                }

                if (trim($paragraphText) !== '') {
                    $textParagraphs[] = trim($paragraphText);
                }
            }

            return implode("\n", $textParagraphs);
        } catch (DocumentExtractionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DocumentExtractionException('Не удалось прочитать текст DOCX.', previous: $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }
}
