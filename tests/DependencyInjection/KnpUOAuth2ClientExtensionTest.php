<?php

/*
 * OAuth2 Client Bundle
 * Copyright (c) KnpUniversity <http://knpuniversity.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KnpU\OAuth2ClientBundle\tests\DependencyInjection;

use KnpU\OAuth2ClientBundle\DependencyInjection\KnpUOAuth2ClientExtension;
use KnpU\OAuth2ClientBundle\DependencyInjection\Providers\ProviderConfiguratorInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class KnpUOAuth2ClientExtensionTest extends \PHPUnit_Framework_TestCase
{
    /** @var ContainerBuilder */
    protected $configuration;

    public function testNoClientMakesNoServices()
    {
        $this->configuration = new ContainerBuilder();
        $loader = new KnpUOAuth2ClientExtension();
        $config = [];
        $loader->load([$config], $this->configuration);

        $this->assertFalse($this->configuration->hasDefinition('knpu.oauth2.facebook_client'));
    }

    public function testFacebookProviderMakesService()
    {
        $this->configuration = new ContainerBuilder();
        $loader = new KnpUOAuth2ClientExtension(false);
        $config = ['clients' => ['facebook' => [
            'type' => 'facebook',
            'client_id' => 'CLIENT_ID',
            'client_secret' => 'SECRET',
            'graph_api_version' => 'API_VERSION',
            'redirect_route' => 'the_route_name',
            'redirect_params' => ['route_params' => 'foo'],
        ]]];
        $loader->load([$config], $this->configuration);

        $providerDefinition = $this->configuration->getDefinition('knpu.oauth2.provider.facebook');

        $factory = $providerDefinition->getFactory();
        // make sure the factory is correct
        $this->assertEquals(
            [new Reference('knpu.oauth2.provider_factory'), 'createProvider'],
            $factory
        );

        $this->assertEquals(
            [
                'League\OAuth2\Client\Provider\Facebook',
                ['clientId' => 'CLIENT_ID', 'clientSecret' => 'SECRET', 'graphApiVersion' => 'API_VERSION'],
                'the_route_name',
                ['route_params' => 'foo'],
            ],
            // these arguments will be passed to the factory's method
            $providerDefinition->getArguments()
        );

        // the custom class is used
        $clientDefinition = $this->configuration->getDefinition('knpu.oauth2.client.facebook');
        $this->assertEquals(
            'KnpU\OAuth2ClientBundle\Client\Provider\FacebookClient',
            $clientDefinition->getClass()
        );
        $this->assertEquals(
            [
                new Reference('knpu.oauth2.provider.facebook'),
                new Reference('request_stack'),
            ],
            $clientDefinition->getArguments()
        );

        // the client service has an alias
        $this->assertTrue(
            $this->configuration->hasAlias('KnpU\OAuth2ClientBundle\Client\Provider\FacebookClient'),
            'FacebookClient service is missing an alias'
        );
    }

    /**
     * @dataProvider getAllProviderConfigurators
     */
    public function testProviderConfiguratorsAreFullyImplemented(ProviderConfiguratorInterface $providerConfigurator)
    {
        $this->assertRegexp('#^[ \w]+$#', $providerConfigurator->getProviderDisplayName());
        if ('Generic' !== $providerConfigurator->getProviderDisplayName()) {
            $this->assertRegexp('#^[\w-]+/[\w-]+$#', $providerConfigurator->getPackagistName());
            $this->assertNotFalse(filter_var($providerConfigurator->getLibraryHomepage(), FILTER_VALIDATE_URL));
            $this->assertTrue(class_exists($providerConfigurator->getClientClass([])));
        }
    }

    public function getAllProviderConfigurators()
    {
        $extension = new KnpUOAuth2ClientExtension();
        $configurators = [];
        foreach (KnpUOAuth2ClientExtension::getAllSupportedTypes() as $type) {
            $configurators[$type] = [$extension->getConfigurator($type)];
        }
        return $configurators;
    }

    /**
     * @dataProvider provideTypesAndConfig
     */
    public function testAllClientsCreateDefinition($type, array $inputConfig)
    {
        $this->configuration = new ContainerBuilder();
        $loader = new KnpUOAuth2ClientExtension(false);
        $inputConfig['type'] = $type;
        $config = ['clients' => ['test_service' => $inputConfig]];
        $loader->load([$config], $this->configuration);

        $this->assertTrue($this->configuration->hasDefinition('knpu.oauth2.provider.test_service'));
        $this->assertTrue($this->configuration->hasDefinition('knpu.oauth2.client.test_service'));
    }

    public function provideTypesAndConfig()
    {
        $tests = [];
        $extension = new KnpUOAuth2ClientExtension();

        foreach (KnpUOAuth2ClientExtension::getAllSupportedTypes() as $type) {
            $configurator = $extension->getConfigurator($type);
            $tree = new TreeBuilder();
            $configNode = $tree->root('testing');
            $configurator->buildConfiguration($configNode->children(), $type);

            /** @var ArrayNode $arrayNode */
            $arrayNode = $tree->buildTree();
            $config = [
                'client_id' => 'CLIENT_ID_TEST',
                'client_secret' => 'CLIENT_SECRET_TEST',
                'redirect_route' => 'go_there',
                'redirect_params' => [],
                'use_state' => rand(0, 1) == 0,
            ];
            // loop through and assign some random values
            foreach ($arrayNode->getChildren() as $child) {
                /** @var NodeInterface $child */
                if ($child instanceof ArrayNode) {
                    $config[$child->getName()] = [];
                } elseif ($child instanceof BooleanNode) {
                    $config[$child->getName()] = (bool) rand(0, 1);
                } else {
                    $config[$child->getName()] = rand();
                }
            }

            $tests[] = [$type, $config];
        }

        return $tests;
    }

    public function testGenericProvider()
    {
        $clientConfig = [
            'type' => 'generic',
            'client_id' => 'abc',
            'client_secret' => '123',
            'redirect_route' => 'foo_bar_route',
            'redirect_params' => [],
            'provider_class' => 'Foo\Bar\Provider',
            'client_class' => 'Foo\Bar\Client',
            'provider_options' => [
                'foo' => true,
                'bar' => 'baz',
                'cool_stuff' => ['pizza', 'popcorn'],
            ],
        ];

        $this->configuration = new ContainerBuilder();
        $loader = new KnpUOAuth2ClientExtension(false);
        $config = ['clients' => [
            'custom_provider' => $clientConfig,
        ]];
        $loader->load([$config], $this->configuration);

        $providerDefinition = $this->configuration->getDefinition('knpu.oauth2.provider.custom_provider');
        $this->assertEquals(
            'Foo\Bar\Provider',
            $providerDefinition->getClass()
        );

        $this->assertEquals(
            [
                'Foo\Bar\Provider',
                [
                    'clientId' => 'abc',
                    'clientSecret' => '123',
                    'foo' => true,
                    'bar' => 'baz',
                    'cool_stuff' => ['pizza', 'popcorn'],
                ],
                'foo_bar_route',
                [],
            ],
            // these arguments will be passed to the factory's method
            $providerDefinition->getArguments()
        );

        // the custom class is used
        $clientDefinition = $this->configuration->getDefinition('knpu.oauth2.client.custom_provider');
        $this->assertEquals(
            'Foo\Bar\Client',
            $clientDefinition->getClass()
        );
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @dataProvider provideBadConfiguration
     */
    public function testBadClientsConfiguration(array $badClientsConfig)
    {
        $this->configuration = new ContainerBuilder();
        $loader = new KnpUOAuth2ClientExtension(false);
        $config = ['clients' => $badClientsConfig];
        $loader->load([$config], $this->configuration);
    }

    public function provideBadConfiguration()
    {
        $tests = [];

        $goodConfig = [
            'type' => 'facebook',
            'client_id' => 'abc',
            'client_secret' => '123',
            'redirect_route' => 'foo_bar_route',
            'redirect_params' => [],
        ];

        $badConfig1 = $goodConfig;
        unset($badConfig1['type']);
        $tests[] = ['facebook1' => $badConfig1];

        $badConfig2 = $goodConfig;
        unset($badConfig2['client_id']);
        $tests[] = ['facebook1' => $badConfig2];

        $badConfig3 = $goodConfig;
        unset($badConfig3['client_secret']);
        $tests[] = ['facebook1' => $badConfig3];

        $badConfig4 = $goodConfig;
        unset($badConfig4['redirect_uri']);
        $tests[] = ['facebook1' => $badConfig4];

        $badConfig5 = $goodConfig;
        unset($badConfig5['redirect_params']);
        $tests[] = ['facebook1' => $badConfig5];

        $badConfig6 = $goodConfig;
        $badConfig6['redirect_paras'] = 'NOT AN ARRAY';
        $tests[] = ['facebook1' => $badConfig6];

        $badConfig7 = $goodConfig;
        $badConfig7['type'] = 'fake_type';
        $tests[] = ['facebook1' => $badConfig7];

        return $tests;
    }

    public function testGetAllSupportedTypes()
    {
        $types = KnpUOAuth2ClientExtension::getAllSupportedTypes();

        $this->assertTrue(in_array('facebook', $types, true));
    }

    public function testGetAlias()
    {
        $extension = new KnpUOAuth2ClientExtension();
        $this->assertEquals('knpu_oauth2_client', $extension->getAlias());
    }

    protected function tearDown()
    {
        unset($this->configuration);
    }
}
