<?php

/*
 * This file is part of the Search PHP Bundle.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 * @author PuntMig Technologies
 */

declare(strict_types=1);

namespace Apisearch\DependencyInjection\CompilerPass;

use Apisearch\Event\HttpEventRepository;
use Apisearch\Event\InMemoryEventRepository;
use Apisearch\Http\GuzzleClient;
use Apisearch\Http\TestClient;
use Apisearch\Repository\HttpRepository;
use Apisearch\Repository\InMemoryRepository;
use Apisearch\Repository\TransformableRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * File header placeholder.
 */
class RepositoryCompilerPass implements CompilerPassInterface
{
    /**
     * You can modify the container here before it is dumped to PHP code.
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $repositoryConfigurations = $container->getParameter('apisearch.repository_configuration');
        foreach ($repositoryConfigurations as $name => $repositoryConfiguration) {
            $this->createClient(
                $container,
                $name,
                $repositoryConfiguration
            );

            $this->createSearchRepository(
                $container,
                $name,
                $repositoryConfiguration
            );

            $this->createEventRepository(
                $container,
                $name,
                $repositoryConfiguration
            );
        }
    }

    /**
     * Create client.
     *
     * @param ContainerBuilder $container
     * @param string           $name
     * @param array            $repositoryConfiguration
     */
    private function createClient(
        ContainerBuilder $container,
        string $name,
        array $repositoryConfiguration
    ) {
        if ($repositoryConfiguration['http']) {
            $repositoryConfiguration['test']
                ? $container
                    ->register('apisearch.client_'.$name, TestClient::class)
                    ->addArgument(new Reference('test.client'))
                : $container
                    ->register('apisearch.client_'.$name, GuzzleClient::class)
                    ->setArguments([
                        $repositoryConfiguration['endpoint'],
                        $repositoryConfiguration['version'],
                    ]);
        }
    }

    /**
     * Create a repository by connection configuration.
     *
     * @param ContainerBuilder $container
     * @param string           $name
     * @param array            $repositoryConfiguration
     */
    private function createSearchRepository(
        ContainerBuilder $container,
        string $name,
        array $repositoryConfiguration
    ) {
        if (
            is_null($repositoryConfiguration['search']['repository_service']) ||
            ($repositoryConfiguration['search']['repository_service'] == 'apisearch.repository_'.$name)
        ) {
            $repoDefinition = $repositoryConfiguration['search']['in_memory']
                ? $container->register('apisearch.repository_'.$name, InMemoryRepository::class)
                : $container
                    ->register('apisearch.repository_'.$name, HttpRepository::class)
                    ->addArgument(new Reference('apisearch.client_'.$name))
                    ->addArgument($repositoryConfiguration['write_async']);
        } else {
            $container
                ->addAliases([
                    'apisearch.repository_'.$name => $repositoryConfiguration['search']['repository_service'],
                ]);

            $repoDefinition = $container->getDefinition($repositoryConfiguration['search']['repository_service']);
        }

        $this->injectRepositoryCredentials(
            $repoDefinition,
            $repositoryConfiguration
        );

        $definition = $container
            ->register('apisearch.repository_transformable_'.$name, TransformableRepository::class)
            ->setDecoratedService('apisearch.repository_'.$name)
            ->addArgument(new Reference('apisearch.repository_transformable_'.$name.'.inner'))
            ->addArgument(new Reference('apisearch.transformer'))
            ->setPublic(false);

        $this->injectRepositoryCredentials(
            $definition,
            $repositoryConfiguration
        );

        $container
            ->getDefinition('apisearch.repository_bucket')
            ->addMethodCall(
                'addRepository',
                [$name, new Reference('apisearch.repository_'.$name)]
            );
    }

    /**
     * Create event repository.
     *
     * @param ContainerBuilder $container
     * @param string           $name
     * @param array            $repositoryConfiguration
     */
    private function createEventRepository(
        ContainerBuilder $container,
        string $name,
        array $repositoryConfiguration
    ) {
        if (
            is_null($repositoryConfiguration['event']['repository_service']) ||
            ($repositoryConfiguration['event']['repository_service'] == 'apisearch.event_repository_'.$name)
        ) {
            $repositoryConfiguration['event']['in_memory']
                ? $container
                    ->register('apisearch.event_repository_'.$name, InMemoryEventRepository::class)
                    ->addMethodCall('setAppId', [
                        $repositoryConfiguration['app_id'],
                    ])
                : $container
                    ->register('apisearch.event_repository_'.$name, HttpEventRepository::class)
                    ->addArgument(new Reference('apisearch.client_'.$name))
                    ->addMethodCall('setCredentials', [
                        $repositoryConfiguration['app_id'],
                        $repositoryConfiguration['secret'],
                    ]);
        } else {
            $repoDefinition = $container->getDefinition($repositoryConfiguration['event']['repository_service']);
            $this->injectRepositoryCredentials(
                $repoDefinition,
                $repositoryConfiguration
            );

            $container
                ->addAliases([
                    'apisearch.event_repository_'.$name => $repositoryConfiguration['event']['repository_service'],
                ]);
        }
    }

    /**
     * Inject credentials in repository.
     *
     * @param Definition $definition
     * @param array      $repositoryConfiguration
     */
    private function injectRepositoryCredentials(
        Definition $definition,
        array $repositoryConfiguration
    ) {
        if ($repositoryConfiguration['app_id']) {
            $repositoryConfiguration['secret']
                ? $definition->addMethodCall('setCredentials', [
                    $repositoryConfiguration['app_id'],
                    $repositoryConfiguration['secret'],
                ])
                : $definition->addMethodCall('setAppId', [
                    $repositoryConfiguration['app_id'],
                ]);
        }
    }
}
