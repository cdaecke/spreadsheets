<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\ViewHelpers\Reader;

use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class FileReferenceViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly ResourceFactory $resourceFactory)
    {
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('uid', 'string', 'File reference uid to resolve', false);
    }

    public function render(): ?FileReference
    {
        if (empty($this->arguments['uid'])) {
            $this->arguments['uid'] = $this->renderChildren();
        }
        if (is_numeric($this->arguments['uid']) === false) {
            return null;
        }

        try {
            return $this->resourceFactory->getFileReferenceObject((int) $this->arguments['uid']);
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
