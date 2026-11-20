<?php

declare(strict_types=1);

use SpeedySpec\CliWordPress\Kubernetes;

it('runs WordPress from a claim, one pod at a time', function ()
{
    [$claim, $deployment, $service] = Kubernetes::resources('blog');
    expect($claim['kind'])->toBe('PersistentVolumeClaim')
        ->and($claim['metadata']['name'])->toBe('blog-files')
        ->and($claim['spec']['accessModes'])->toBe(['ReadWriteOnce'])
        ->and($deployment['kind'])->toBe('Deployment')
        ->and($deployment['metadata']['name'])->toBe('blog')
        ->and($deployment['spec']['strategy'])->toBe(['type' => 'Recreate'])
        ->and($service['spec']['ports'])->toBe([['name' => 'http', 'port' => 80, 'targetPort' => 80]]);
    $container = $deployment['spec']['template']['spec']['containers'][0];
    expect($container['name'])->toBe(Kubernetes::CONTAINER)
        ->and($container['image'])->toStartWith('wordpress@sha256:')
        ->and($container['volumeMounts'])->toBe([['name' => 'files', 'mountPath' => Kubernetes::ROOT]])
        ->and($container)->not->toHaveKey('env');
});

it('is ready once the image has put WordPress in the volume', function ()
{
    $deployment = Kubernetes::resources('blog')[1];
    $probe = $deployment['spec']['template']['spec']['containers'][0]['readinessProbe'];
    expect($probe['exec']['command'])->toBe(['test', '-f', Kubernetes::ROOT . '/wp-config-sample.php']);
});

it('selects its own pods only', function ()
{
    $deployment = Kubernetes::resources('blog')[1];
    $labels = ['app.kubernetes.io/name' => 'wordpress', 'app.kubernetes.io/instance' => 'blog'];
    expect($deployment['spec']['selector']['matchLabels'])->toBe($labels)
        ->and($deployment['spec']['template']['metadata']['labels'])->toBe($labels)
        ->and(Kubernetes::resources('blog')[2]['spec']['selector'])->toBe($labels);
});
