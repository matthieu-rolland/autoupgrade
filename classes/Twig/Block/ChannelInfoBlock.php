<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\AutoUpgrade\Twig\Block;

use PrestaShop\Module\AutoUpgrade\ChannelInfo;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeConfiguration;
use PrestaShop\Module\AutoUpgrade\UpgradeSelfCheck;
use Twig_Environment;

class ChannelInfoBlock
{
    const PS_VERSION_DISPLAY_MAX_PRECISION = 3;

    const PS_MINIMAL_VERSION = '1.6.1.18';

    /**
     * @var UpgradeConfiguration
     */
    private $config;

    /**
     * @var ChannelInfo
     */
    private $channelInfo;

    /**
     * @var Twig_Environment|\Twig\Environment
     */
    private $twig;

    /**
     * ChannelInfoBlock constructor.
     *
     * @param UpgradeConfiguration $config
     * @param ChannelInfo $channelInfo
     * @param Twig_Environment|\Twig\Environment $twig
     */
    public function __construct(UpgradeConfiguration $config, ChannelInfo $channelInfo, $twig)
    {
        $this->config = $config;
        $this->channelInfo = $channelInfo;
        $this->twig = $twig;
    }

    /**
     * @return string HTML
     */
    public function render()
    {
        $channel = $this->channelInfo->getChannel();
        $upgradeInfo = $this->channelInfo->getInfo();
        $versionNum = isset($upgradeInfo['version_num']) ? $upgradeInfo['version_num'] : '';

        if ($channel == 'private') {
            $upgradeInfo['link'] = $this->config->get('private_release_link');
            $upgradeInfo['md5'] = $this->config->get('private_release_md5');
        }

        $compatibilityDisplay = $this->buildCompatibilityTableDisplay();

        return $this->twig->render(
            '@ModuleAutoUpgrade/block/channelInfo.twig',
            [
                'upgradeInfo' => $upgradeInfo,
                'allPhpVersions' => UpgradeSelfCheck::PHP_VERSIONS_DISPLAY,
                'psPhpCompatibilityRanges' => $compatibilityDisplay['psPhpCompatibilityRanges'],
                'requiredPhpVersion' => $compatibilityDisplay['requiredPhpVersion'],
                'currentFormattedPhpVersion' => $this->getFormattedVersion(PHP_VERSION),
                'targetFormattedPSVersion' => $this->getFormattedVersion($versionNum, self::PS_VERSION_DISPLAY_MAX_PRECISION),
            ]
        );
    }

    /**
     * Builds array of formatted data for the compatibility table display
     *
     * @return array
     */
    public function buildCompatibilityTableDisplay()
    {
        $startPrestaShopVersion = $labelStartPrestaShopVersion = $previousPHPRange = $previousPrestaVersion = $requiredPhpVersion = null;
        $result = [];
        $toParse = UpgradeSelfCheck::PHP_PS_VERSIONS;
        $isCurrentPrestaVersion = false;
        $versionNum = isset($this->channelInfo->getInfo()['version_num']) ? $this->channelInfo->getInfo()['version_num'] : '';
        foreach ($toParse as $prestashopVersion => $phpVersions) {
            end($toParse);
            $isLastIteration = $prestashopVersion === key($toParse);

            if ($startPrestaShopVersion === null) {
                $previousPHPRange = $phpVersions;
                $startPrestaShopVersion = $labelStartPrestaShopVersion = $prestashopVersion;
            }

            if ($isLastIteration) {
                $startPrestaShopVersion = $prestashopVersion;
            }
            if (!$isCurrentPrestaVersion) {
                $isCurrentPrestaVersion = $this->isCurrentPrestashopVersion($startPrestaShopVersion, _PS_VERSION_);
            }

            // we're still in the same php range, it means that we are in the case of a grouped prestashop version, ex: 1.7.0 ~ 1.7.3
            if ($phpVersions === $previousPHPRange) {
                $previousPrestaVersion = $prestashopVersion;
                $startPrestaShopVersion = $prestashopVersion;
            } else {
                $label = $this->buildPSLabel($labelStartPrestaShopVersion, $previousPrestaVersion);
                $result[$label]['php_versions'] = $this->buildPhpVersionsList($previousPHPRange);
                $result[$label]['is_current'] = $isCurrentPrestaVersion;

                $labelStartPrestaShopVersion = $startPrestaShopVersion = $prestashopVersion;
                $result[$label]['is_target'] = $this->getFormattedVersion($versionNum, self::PS_VERSION_DISPLAY_MAX_PRECISION) === $label;
                if ($result[$label]['is_target']) {
                    $requiredPhpVersion = $previousPHPRange[0];
                }
                $previousPrestaVersion = null;
                $isCurrentPrestaVersion = false;
            }
            if ($isLastIteration) {
                $result[$prestashopVersion]['php_versions'] = $this->buildPhpVersionsList($phpVersions);
                $result[$prestashopVersion]['is_current'] = $isCurrentPrestaVersion;
                $result[$prestashopVersion]['is_target'] = $this->getFormattedVersion($versionNum, self::PS_VERSION_DISPLAY_MAX_PRECISION) === $prestashopVersion;
            }
            $previousPHPRange = $phpVersions;
        }

        return ['requiredPhpVersion' => $requiredPhpVersion, 'psPhpCompatibilityRanges' => $result];
    }

    /**
     * Builds PrestaShop version label for display
     *
     * @param string $startVersion
     * @param string $endVersion
     *
     * @return string
     */
    public function buildPSLabel($startVersion, $endVersion)
    {
        if ($startVersion === self::PS_MINIMAL_VERSION) {
            return '>= ' . self::PS_MINIMAL_VERSION;
        }

        return $startVersion .= $endVersion ? ' ~ ' . $endVersion : '';
    }

    /**
     * Builds a list of php versions for a given php version range
     *
     * @param array $phpVersionRange
     *
     * @return array
     */
    public function buildPhpVersionsList($phpVersionRange)
    {
        if (!is_array($phpVersionRange) || !is_string($phpVersionRange[0]) || !is_string($phpVersionRange[1])) {
            throw new \InvalidArgumentException('$phpVersionRange must be an array containing 2 elements (start and end php versions');
        }
        $phpStart = $phpVersionRange[0];
        $phpEnd = $phpVersionRange[1];
        $phpVersionsList = [];
        $inRange = false;
        foreach (UpgradeSelfCheck::PHP_VERSIONS_DISPLAY as $phpVersion) {
            if ($phpVersion === $phpStart) {
                $inRange = true;
            }
            if ($inRange) {
                $phpVersionsList[] = $phpVersion;
            }
            if ($phpVersion === $phpEnd) {
                break;
            }
        }

        return $phpVersionsList;
    }

    /**
     * Find out if a given prestashop version is equal to the one currently used
     * (not taking patch versions into account)
     *
     * @param string $prestaversion
     * @param string $currentPrestaShopVersion
     *
     * @return bool
     */
    public function isCurrentPrestashopVersion($prestaversion, $currentPrestaShopVersion)
    {
        // special case for 1.6.1 versions
        if (substr($currentPrestaShopVersion, 0, 5) === '1.6.1' && $prestaversion === self::PS_MINIMAL_VERSION) {
            return version_compare($currentPrestaShopVersion, $prestaversion, '>=');
        }
        $explodedCurrentPSVersion = explode('.', $currentPrestaShopVersion);
        $shortenCurrentPrestashop = implode('.', array_slice($explodedCurrentPSVersion, 0, count(explode('.', $prestaversion))));

        return $prestaversion === $shortenCurrentPrestashop;
    }

    /**
     * Gets display (shortened) version for a given version and maximum precision
     *
     * @param string $version
     * @param int $maxPrecision
     *
     * @return string
     */
    private function getFormattedVersion($version, $maxPrecision = 2)
    {
        $explodedVersion = array_slice(explode('.', $version), 0, $maxPrecision);

        return implode('.', $explodedVersion);
    }
}
