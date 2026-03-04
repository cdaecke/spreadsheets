<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\EventListener;

use Hoogi91\Spreadsheets\Domain\ValueObject\DsnValueObject;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;

class DataHandlerEventListener
{
    /**
     * @var array<int, array<mixed>|null>
     */
    private array $records = [];

    /**
     * @var array<string, array<string, array<string, array<string>>>>
     */
    private array $activationTypes = [];

    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly ConnectionPool $connectionPool
    ) {
        foreach ($GLOBALS['TCA'] ?? [] as $table => $tca) {
            $table = (string)$table;
            foreach ($tca['columns'] ?? [] as $column => $conf) {
                if (
                    isset($conf['config']['renderType'], $conf['config']['uploadField'])
                    && $conf['config']['renderType'] === 'spreadsheetInput'
                ) {
                    $this->activationTypes[$table]['*'][(string)$conf['config']['uploadField']][] = $column;
                }
            }

            foreach ($GLOBALS['TCA'][$table]['types'] ?? [] as $cType => $type) {
                $cType = (string)$cType;
                foreach ($type['columnsOverrides'] ?? [] as $column => $conf) {
                    if (
                        isset($conf['config']['renderType'], $conf['config']['uploadField'])
                        && $conf['config']['renderType'] === 'spreadsheetInput'
                    ) {
                        $this->activationTypes[$table][$cType][(string)$conf['config']['uploadField']][] = $column;
                    }
                }
            }
        }
    }

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        // skip processing for not found uid or irrelevant status
        $uid = $dataHandler->substNEWwithIDs[$id] ?? (is_int($id) ? $id : null);
        if ($uid === null || !in_array($status, ['new', 'update'], true)) {
            return;
        }

        // ignore if handler should not process for table and/or cType
        $cType = $fieldArray['CType'] ?? $this->getBackendRecordField($uid, $table, 'CType');
        if (!isset($this->activationTypes[$table]['*']) && !isset($this->activationTypes[$table][$cType])) {
            return;
        }

        $activationConfig = $this->activationTypes[$table][$cType] ?? $this->activationTypes[$table]['*'] ?? [];
        foreach ($activationConfig as $uploadField => $renderFields) {
            // truncate render fields after update if assets have been removed
            if (($fieldArray[$uploadField] ?? null) === 0) {
                if ($status === 'update') {
                    $this->connectionPool
                        ->getConnectionForTable($table)
                        ->update($table, array_fill_keys($renderFields, ''), ['uid' => $uid]);
                }

                continue;
            }

            // if upload field was filled we get its relations and start to update all render fields if required
            /** @var array<FileReference> $relations */
            $relations = $this->fileRepository->findByRelation($table, $uploadField, $uid);
            foreach ($renderFields as $renderField) {
                $this->setSpreadsheetValue($uid, $table, $status, $renderField, $relations);
            }
        }
    }

    /**
     * @param array<FileReference> $relations
     */
    private function setSpreadsheetValue(
        int $uid,
        string $table,
        string $status,
        string $field,
        array $relations
    ): void {
        if (empty($relations)) {
            return;
        }

        // if backend record field is currently empty we pre-select with first relation
        $fieldValue = $this->getBackendRecordField($uid, $table, $field);
        if (empty($fieldValue) === true) {
            $this->connectionPool
                ->getConnectionForTable($table)
                ->update($table, [$field => 'spreadsheet://' . $relations[0]->getUid()], ['uid' => $uid]);
        } elseif ($status === 'new' && is_string($fieldValue)) {
            $dsn = $this->getTranslatedSpreadsheetDsn(
                DsnValueObject::createFromDSN($fieldValue),
                $relations
            );
            if ($dsn !== null) {
                $this->connectionPool
                    ->getConnectionForTable($table)
                    ->update($table, [$field => $dsn], ['uid' => $uid]);
            }
        }
    }

    /**
     * @param array<FileReference> $references
     */
    private function getTranslatedSpreadsheetDsn(DsnValueObject $dsn, array $references): ?string
    {
        foreach ($references as $reference) {
            if ($reference->getReferenceProperty('l10n_parent') === $dsn->getFileReference()) {
                return str_replace(
                    'spreadsheet://' . $dsn->getFileReference(),
                    'spreadsheet://' . $reference->getUid(),
                    $dsn->getDsn()
                );
            }
        }

        return null;
    }

    private function getBackendRecordField(int $uid, string $table, string $field): mixed
    {
        if (!isset($this->records[$uid])) {
            $this->records[$uid] = BackendUtility::getRecord($table, $uid); // @codeCoverageIgnore
        }

        return $this->records[$uid][$field] ?? null;
    }
}
