<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

/**
 * Describes WordPress on Kubernetes: the official image serving it, its files on a claim.
 *
 * The image puts WordPress into the empty volume when its pod first starts, and the CLI then writes wp-config.php
 * there in a runner pod, so the database password is never in the Deployment. The pod is ready only once the files
 * are in place, so setup never runs before them; the claim is ReadWriteOnce, so an update stops the old pod first.
 */
final class Kubernetes
{
    /** The image, pinned by digest: WordPress 6.8 on PHP 8.3 with Apache. */
    public const IMAGE = 'wordpress@sha256:30bff39330d1693b0ce13d32fc9b7bb67193064f040b7d60d3494e136fa599d4';

    /** The container that serves WordPress. */
    public const CONTAINER = 'wordpress';

    /** The install root inside it. */
    public const ROOT = '/var/www/html';

    /**
     * Builds the resources of the install named `$name`: its claim, its Deployment and its Service.
     *
     * @return list<array<string, mixed>>
     *   The resources, in the order they are applied.
     */
    public static function resources(string $name): array
    {
        $labels = ['app.kubernetes.io/name' => 'wordpress', 'app.kubernetes.io/instance' => $name];

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => ['name' => "$name-files", 'labels' => $labels],
                'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '2Gi']]],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => ['name' => $name, 'labels' => $labels],
                'spec' => [
                    'replicas' => 1,
                    'strategy' => ['type' => 'Recreate'],
                    'selector' => ['matchLabels' => $labels],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [[
                                'name' => self::CONTAINER,
                                'image' => self::IMAGE,
                                'ports' => [['name' => 'http', 'containerPort' => 80]],
                                'readinessProbe' => [
                                    'exec' => ['command' => ['test', '-f', self::ROOT . '/wp-config-sample.php']],
                                    'periodSeconds' => 2,
                                ],
                                'volumeMounts' => [['name' => 'files', 'mountPath' => self::ROOT]],
                            ]],
                            'volumes' => [['name' => 'files', 'persistentVolumeClaim' => ['claimName' => "$name-files"]]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => ['name' => $name, 'labels' => $labels],
                'spec' => ['selector' => $labels, 'ports' => [['name' => 'http', 'port' => 80, 'targetPort' => 80]]],
            ],
        ];
    }
}
