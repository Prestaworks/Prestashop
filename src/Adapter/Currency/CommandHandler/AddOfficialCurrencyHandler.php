<?php
/**
 * 2007-2019 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\PrestaShop\Adapter\Currency\CommandHandler;

use Currency;
use Language;
use PrestaShop\PrestaShop\Adapter\Currency\CurrencyCommandValidator;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\AddOfficialCurrencyCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\CommandHandler\AddCurrencyHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CannotCreateCurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Currency\ValueObject\CurrencyId;
use PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository;
use PrestaShop\PrestaShop\Core\Localization\Exception\LocalizationException;
use PrestaShopException;

/**
 * Adds a new currency.
 *
 * @internal
 */
final class AddOfficialCurrencyHandler extends AbstractCurrencyHandler implements AddCurrencyHandlerInterface
{
    /**
     * @var Language
     */
    protected $defaultLanguage;

    /**
     * @var CurrencyCommandValidator
     */
    protected $validator;

    /**
     * @param LocaleRepository $localeRepoCLDR
     * @param int $defaultLanguageId
     */
    public function __construct(
        LocaleRepository $localeRepoCLDR,
        $defaultLanguageId
    ) {
        parent::__construct($localeRepoCLDR);
        $this->defaultLanguage = new Language((int) $defaultLanguageId);
        $this->validator = new CurrencyCommandValidator($localeRepoCLDR, $this->defaultLanguage->getLocale());
    }

    /**
     * {@inheritdoc}
     *
     * @throws CannotCreateCurrencyException
     * @throws CurrencyConstraintException
     * @throws CurrencyException
     * @throws CurrencyNotFoundException
     * @throws LocalizationException
     */
    public function handle(AddOfficialCurrencyCommand $command)
    {
        $this->validator->assertCurrencyIsNotAvailableInDatabase($command->getIsoCode()->getValue());

        try {
            $entity = new Currency();

            $entity->iso_code = $command->getIsoCode()->getValue();
            $entity->active = $command->isEnabled();
            $entity->unofficial = false;
            $entity->conversion_rate = $command->getExchangeRate()->getValue();
            $entity->numeric_iso_code = $this->findNumericIsoCodeFromAlphaCode($command->getIsoCode()->getValue());
            $entity->precision = $this->getPrecision($command);

            if (!empty($command->getLocalizedNames())) {
                $entity->setNames($command->getLocalizedNames());
            }
            if (!empty($command->getLocalizedSymbols())) {
                $entity->setSymbols($command->getLocalizedSymbols());
            }
            //This method will insert the missing localized names/symbols and detect if the currency has been modified
            $entity->refreshLocalizedCurrencyData(Language::getLanguages(), $this->localeRepoCLDR);

            if (false === $entity->add()) {
                throw new CannotCreateCurrencyException('Failed to create new currency');
            }

            $this->associateWithShops($entity, $command->getShopIds());
            $this->associateConversionRateToShops($entity, $command->getShopIds());
        } catch (PrestaShopException $exception) {
            throw new CurrencyException('Failed to create new currency', 0, $exception);
        }

        return new CurrencyId((int) $entity->id);
    }

    /**
     * @param AddOfficialCurrencyCommand $command
     *
     * @return int
     */
    private function getPrecision(AddOfficialCurrencyCommand $command): int
    {
        if (null !== $command->getPrecision()) {
            return $command->getPrecision()->getValue();
        }

        // CLDR locale give us the CLDR reference specification
        $cldrLocale = $this->localeRepoCLDR->getLocale($this->defaultLanguage->getLocale());
        // CLDR currency gives data from CLDR reference, for the given language
        $cldrCurrency = $cldrLocale->getCurrency($command->getIsoCode()->getValue());

        return $cldrCurrency->getDecimalDigits();
    }

    /**
     * @param string $isoCode
     *
     * @return string
     *
     * @throws CurrencyNotFoundException
     */
    private function findNumericIsoCodeFromAlphaCode($isoCode): string
    {
        $defaultLocaleCLDR = $this->localeRepoCLDR->getLocale($this->defaultLanguage->getLocale());
        $allCurrencies = $defaultLocaleCLDR->getAllCurrencies();

        $matchingRealCurrency = null;
        foreach ($allCurrencies as $currencyData) {
            if ($currencyData->getIsoCode() == $isoCode) {
                $matchingRealCurrency = $currencyData;
                break;
            }
        }

        if (null === $matchingRealCurrency) {
            throw new CurrencyNotFoundException(
                sprintf(
                    'ISO code "%s" does not match any currency in CLDR database',
                    $isoCode
                )
            );
        }

        return $matchingRealCurrency->getNumericIsoCode();
    }
}
