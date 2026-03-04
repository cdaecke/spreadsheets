<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Form\Element;

use Hoogi91\Spreadsheets\Domain\ValueObject\DsnValueObject;
use Hoogi91\Spreadsheets\Exception\InvalidDataSourceNameException;
use Hoogi91\Spreadsheets\Service\ExtractorService;
use Hoogi91\Spreadsheets\Service\ReaderService;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\View\ViewInterface;

class DataInputElement extends AbstractFormElement
{
    private const DEFAULT_TEMPLATE_PATH = 'EXT:spreadsheets/Resources/Private/Templates/FormElement/DataInput.html';

    public function __construct(private readonly BackendViewFactory $backendViewFactory)
    {
    }

    /**
     * @return array<mixed> As defined in initializeResultArray() of AbstractNode
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $config = $this->data['parameterArray']['fieldConf']['config'] ?? [];
        $view = $this->createView($config);

        // upload field hasn't been specified
        if (array_key_exists($config['uploadField'] ?? '', $this->data['processedTca']['columns'] ?? []) === false) {
            $resultArray['html'] = $view->assign('missingUploadField', true)->render();

            return $resultArray;
        }

        // return alert if no valid file references were uploaded
        $references = $this->getValidFileReferences($config['uploadField']);
        if (empty($references)) {
            $resultArray['html'] = $view->assign('nonValidReferences', true)->render();

            return $resultArray;
        }

        // register additional assets only when input will be rendered
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@hoogi91/spreadsheets/SpreadsheetDataInput.js'
        );
        $resultArray['stylesheetFiles'] = ['EXT:spreadsheets/Resources/Public/Css/SpreadsheetDataInput.css'];

        try {
            $valueObject = DsnValueObject::createFromDSN($this->data['parameterArray']['itemFormElValue'] ?? '');
        } catch (InvalidDataSourceNameException) {
            $valueObject = '';
        }

        $view->assignMultiple(
            [
                'inputName' => $this->data['parameterArray']['itemFormElName'] ?? null,
                'config' => $config,
                'sheetFiles' => $references,
                'sheetData' => $this->getFileReferencesSpreadsheetData($references),
                'valueObject' => $valueObject,
            ]
        );

        $resultArray['html'] = $view->render();

        return $resultArray;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function createView(array $config): ViewInterface
    {
        $view = $this->backendViewFactory->create($GLOBALS['TYPO3_REQUEST'], ['spreadsheets']);
        $view->getRenderingContext()->getTemplatePaths()->setTemplatePathAndFilename(
            $this->getTemplatePath($config)
        );
        $view->assign('inputSize', (int)($config['size'] ?? 0));

        return $view;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getTemplatePath(array $config): string
    {
        if (empty($config['template'])) {
            return GeneralUtility::getFileAbsFileName(self::DEFAULT_TEMPLATE_PATH);
        }

        $templatePath = GeneralUtility::getFileAbsFileName($config['template']);
        if (is_file($templatePath) === false) {
            return GeneralUtility::getFileAbsFileName(self::DEFAULT_TEMPLATE_PATH);
        }

        return $templatePath;
    }

    /**
     * @return array<FileReference>
     */
    private function getValidFileReferences(string $fieldName): array
    {
        $references = BackendUtility::resolveFileReferences(
            $this->data['tableName'],
            $fieldName,
            $this->data['databaseRow']
        );
        if (empty($references)) {
            return [];
        }

        return array_filter(
            $references,
            static fn ($reference) => in_array($reference->getExtension(), ReaderService::ALLOWED_EXTENSIONS, true)
        );
    }

    /**
     * @param array<FileReference> $references
     * @return array<mixed>
     */
    private function getFileReferencesSpreadsheetData(array $references): array
    {
        $readerService = GeneralUtility::makeInstance(ReaderService::class);
        $extractorService = GeneralUtility::makeInstance(ExtractorService::class);

        $spreadsheets = $this->getSpreadsheetsByFileReferences($references, $readerService);

        $sheetData = [];
        foreach ($spreadsheets as $fileUid => $spreadsheet) {
            $sheetData[$fileUid] = $this->getWorksheetDataFromSpreadsheet($spreadsheet, $extractorService);
        }

        array_walk_recursive(
            $sheetData,
            static function (&$item): void {
                $item = is_string($item) && mb_detect_encoding($item, 'UTF-8', true) === false
                    ? mb_convert_encoding($item, 'UTF-8', mb_list_encodings())
                    : $item;
            }
        );

        return $sheetData;
    }

    /**
     * @param array<FileReference> $references
     * @return array<Spreadsheet>
     */
    private function getSpreadsheetsByFileReferences(array $references, ReaderService $readerService): array
    {
        $spreadsheets = [];
        foreach ($references as $reference) {
            try {
                $spreadsheets[$reference->getUid()] = $readerService->getSpreadsheet($reference);
            } catch (ReaderException) {
                // ignore reading non-existing or invalid file reference
            }
        }

        return $spreadsheets;
    }

    /**
     * @return array<mixed>
     */
    private function getWorksheetDataFromSpreadsheet(Spreadsheet $spreadsheet, ExtractorService $extractorService): array
    {
        $sheetData = [];
        foreach ($spreadsheet->getAllSheets() as $sheetIndex => $worksheet) {
            try {
                $worksheetRange = 'A1:' . $worksheet->getHighestColumn() . $worksheet->getHighestRow();
                $sheetData[$sheetIndex] = [
                    'name' => $worksheet->getTitle(),
                    'cells' => $extractorService->rangeToCellArray($worksheet, $worksheetRange),
                ];
            } catch (SpreadsheetException) {
                // ignore sheet when an exception occurs
            }
        }

        return $sheetData;
    }
}
