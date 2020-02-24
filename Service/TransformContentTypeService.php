<?php

namespace EMS\CoreBundle\Service;

use Elasticsearch\Client;
use EMS\CommonBundle\Common\Document;
use EMS\CoreBundle\ContentTransformer\ContentTransformContext;
use EMS\CoreBundle\ContentTransformer\ContentTransformInterface;
use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\DataField;
use EMS\CoreBundle\Form\DataField\DataFieldType;
use EMS\CoreBundle\Form\Form\RevisionType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormFactoryInterface;

class TransformContentTypeService
{
    /** @var LoggerInterface */
    private $logger;

    /** @var Client */
    private $client;

    /** @var ContentTypeService */
    private $contentTypeService;

    /** @var DataService */
    private $dataService;

    /** @var FormFactoryInterface */
    private $formFactory;

    const DEFAULT_SCROLL_SIZE = 100;

    public function __construct(
        LoggerInterface $logger,
        Client $client,
        ContentTypeService $contentTypeService,
        DataService $dataService,
        FormFactoryInterface $formFactory
    ) {
        $this->logger = $logger;
        $this->client = $client;
        $this->contentTypeService = $contentTypeService;
        $this->dataService = $dataService;
        $this->formFactory = $formFactory;
    }

    public function transform(ContentType $contentType): \Generator
    {
        $total = $this->getTotal($contentType);
        for ($from = 0; $from < $total; $from = $from + self::DEFAULT_SCROLL_SIZE) {
            $scroll = $this->getScroll($contentType, $from);

            foreach ($scroll['hits']['hits'] as $hit) {
                $isChanged = false;
                $document = new Document($contentType->getName(), $hit['_id'], $hit['_source']);

                $revision = $this->dataService->initNewDraft($document->getContentType(), $document->getOuuid(), null, 'TRANSFORM_CONTENT');
                $revisionType = $this->formFactory->create(RevisionType::class, $revision);

                $result = $this->dataService->walkRecursive($revisionType->get('data'), $hit['_source'], function (string $name, $data, DataFieldType $dataFieldType, DataField $dataField) use (&$isChanged) {
                    if ($data === null) {
                        return [];
                    }

                    $transformer = $this->getTransformer($dataField);
                    $contentTransformContext = ContentTransformContext::fromDataFieldType(\get_class($dataFieldType), $data);
                    if (!empty($transformer) && $transformer->canTransform($contentTransformContext)) {
                        $dataTransformed = $transformer->transform($contentTransformContext);
                        $contentTransformContext->setTransformedData($dataTransformed);
                        if ($contentTransformContext->hasChanges()) {
                            $isChanged = true;
                            return [$name => $dataTransformed];
                        }
                    }

                    return [$name => $data];
                });

                if (!$isChanged) {
                    $this->dataService->discardDraft($revision, false, 'TRANSFORM_CONTENT');
                    yield $document;
                    continue;
                }

                $data = $revision->getRawData();

                foreach ($result['data'] as $key => $value) {
                    $data[$key] = $value;
                }

                $revision->setRawData($data);

                $this->dataService->finalizeDraft($revision, $revisionType, 'TRANSFORM_CONTENT');
                yield $document;
            }
        }
    }

    private function getTransformer(DataField $dataField): ?ContentTransformInterface
    {
        $transformerClass = $dataField->getFieldType()->getMigrationgOption('transformer');
        if ($transformerClass === null) {
            return null;
        }

        return new $transformerClass();
    }

    public function getTotal(ContentType $contentType): int
    {
        $scroll = $this->getScroll($contentType);
        return $scroll['hits']['total'];
    }

    private function getScroll(ContentType $contentType, int $from = 0): array
    {
        return $this->client->search([
            'index' => $this->contentTypeService->getIndex($contentType),
            'size' => self::DEFAULT_SCROLL_SIZE,
            'from' => $from,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['_type' => $contentType->getName()]],
                            ['term' => ['_contenttype' => $contentType->getName()]],
                        ],
                    ],
                ]
            ]
        ]);
    }
}
