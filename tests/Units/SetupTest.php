<?php

namespace GlpiPlugin\Apisession\Tests\Units;

use Glpi\Tests\DbTestCase;

/**
 * Tests for the plugin descriptor and lifecycle functions declared in
 * setup.php and hook.php.
 */
final class SetupTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // GLPI includes hook.php only when it needs a hook: do not depend on the tests that ran before
        require_once dirname(__DIR__, 2) . '/hook.php';
    }

    public function testPluginVersion(): void
    {
        $infos = \plugin_version_apisession();

        $this->assertIsArray($infos);
        $this->assertSame('API Session', $infos['name']);
        $this->assertSame(PLUGIN_APISESSION_VERSION, $infos['version']);
        $this->assertArrayHasKey('author', $infos);
        $this->assertArrayHasKey('license', $infos);
        $this->assertSame('11.0.0', $infos['minGlpiVersion']);
    }

    public function testCheckPrerequisites(): void
    {
        $this->assertTrue(\plugin_apisession_check_prerequisites());
    }

    public function testCheckConfig(): void
    {
        $this->assertTrue(\plugin_apisession_check_config());
    }

    public function testInstall(): void
    {
        $this->assertTrue(\plugin_apisession_install());
    }

    public function testUninstall(): void
    {
        $this->assertTrue(\plugin_apisession_uninstall());
    }

    public function testHookIsRegisteredOnInit(): void
    {
        global $PLUGIN_HOOKS;

        \plugin_init_apisession();

        $this->assertTrue($PLUGIN_HOOKS['csrf_compliant']['apisession']);
    }
}
