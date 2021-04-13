<?php

declare(strict_types=1);

namespace EMS\CoreBundle\Service;

use EMS\CommonBundle\Service\ElasticaService;
use EMS\CommonBundle\Storage\StorageManager;
use EMS\CoreBundle\Form\Data\ElasticaTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;

final class DatatableService
{
    const CONFIG = 'config';
    const ALIASES = 'aliases';
    const CONTENT_TYPES = 'contentTypes';
    private ElasticaService $elasticaService;
    private EnvironmentService $environmentService;
    private LoggerInterface $logger;
    private RouterInterface $router;
    private StorageManager $storageManager;

    public function __construct(LoggerInterface $logger, RouterInterface $router, ElasticaService $elasticaService, StorageManager $storageManager, EnvironmentService $environmentService)
    {
        $this->elasticaService = $elasticaService;
        $this->logger = $logger;
        $this->environmentService = $environmentService;
        $this->router = $router;
        $this->storageManager = $storageManager;
    }

    /**
     * @param string[]             $environmentNames
     * @param string[]             $contentTypeNames
     * @param array<string, mixed> $jsonConfig
     */
    public function generateDatatable(array $environmentNames, array $contentTypeNames, array $jsonConfig): ElasticaTable
    {
        $aliases = $this->convertToAliases($environmentNames);
        $hashConfig = $this->storageManager->saveConfig([
            self::CONFIG => $jsonConfig,
            self::ALIASES => $aliases,
            self::CONTENT_TYPES => $contentTypeNames,
        ]);

        return ElasticaTable::fromConfig($this->elasticaService, $this->getAjaxUrl($hashConfig), $aliases, $contentTypeNames, $jsonConfig);
    }

    public function generateDatatableFromHash(string $hashConfig): ElasticaTable
    {
        $config = $this->parsePersistedConfig($this->storageManager->getContents($hashConfig));

        return ElasticaTable::fromConfig($this->elasticaService, $this->getAjaxUrl($hashConfig), $config[self::ALIASES], $config[self::CONTENT_TYPES], $config[self::CONFIG]);
    }

    /**
     * @param string[] $environmentNames
     *
     * @return string[]
     */
    public function convertToAliases(array $environmentNames): array
    {
        $indexes = [];
        foreach ($environmentNames as $name) {
            $environment = $this->environmentService->getByName($name);
            if (false === $environment) {
                $this->logger->warning('log.service.datatable.environment-not-found', ['name' => $name]);
                continue;
            }
            $indexes[] = $environment->getAlias();
        }

        return $indexes;
    }

    /**
     * @return array{contentTypes: string[], aliases: string[], config: array}
     */
    private function parsePersistedConfig(string $jsonConfig): array
    {
        $parameters = \json_decode($jsonConfig, true);
        if (!\is_array($parameters)) {
            throw new \RuntimeException('Unexpected JSON config');
        }

        $resolver = new OptionsResolver();
        $resolver
            ->setDefaults([
                self::CONTENT_TYPES => [],
                self::ALIASES => [],
                self::CONFIG => [],
            ])
            ->setAllowedTypes(self::CONTENT_TYPES, ['array'])
            ->setAllowedTypes(self::ALIASES, ['array'])
            ->setAllowedTypes(self::CONFIG, ['array'])
        ;
        /** @var array{contentTypes: string[], aliases: string[], config: array} $resolvedParameter */
        $resolvedParameter = $resolver->resolve($parameters);

        return $resolvedParameter;
    }

    public function getAjaxUrl(string $hashConfig): string
    {
        return $this->router->generate('ems_core_datatable_ajax_elastica', ['hashConfig' => $hashConfig]);
    }
}
