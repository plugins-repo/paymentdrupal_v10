<?php

namespace Drupal\commerce_paymentdrupal_v10\Plugin\Commerce\PaymentGateway;

// require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Controller/Paymentdrupal_v10RedirectController.php';


use Drupal;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment;
use Drupal\commerce_payment\Annotation\CommercePaymentGateway;
use Drupal\commerce_payment\Controller;
use Drupal\commerce_payment\PaymentStorage;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Paymentdrupal_v10\Paymentdrupal_v10Common\Paymentdrupal_v10Common;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

define('PF_SOFTWARE_NAME', 'drupalcommerce-2.x');
define('PF_SOFTWARE_VER', '2.32');
define('PF_MODULE_NAME', 'Paymentdrupal_v10-drupalcommerce-2.x');
define('PF_MODULE_VER', '1.4.0');

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paymentdrupal_v10",
 *   label = "Paymentdrupal_v10",
 *   display_label = "Paymentdrupal_v10",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paymentdrupal_v10\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase
{
    protected const TEXT_FIELD    = '#type';
    protected const TITLE         = '#title';
    protected const DEFAULT_VALUE = '#default_value';

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return [
                   'redirect_method' => 'post',
               ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['merchant_id']  = [
            OffsiteRedirect::TEXT_FIELD    => 'textfield',
            OffsiteRedirect::TITLE         => $this->t('Merchant ID'),
            OffsiteRedirect::DEFAULT_VALUE => $this->configuration['merchant_id'],
            '#required'                    => true,
        ];
        $form['partner_name'] = [
            OffsiteRedirect::TEXT_FIELD    => 'textfield',
            OffsiteRedirect::TITLE         => $this->t('Partner Name'),
            OffsiteRedirect::DEFAULT_VALUE => $this->configuration['partner_name'],
            '#required'                    => true,
        ];
        $form['secret_key'] = [
            OffsiteRedirect::TEXT_FIELD    => 'textfield',
            OffsiteRedirect::TITLE         => $this->t('Secret Key'),
            OffsiteRedirect::DEFAULT_VALUE => $this->configuration['secret_key'],
            '#required'                    => true,
        ];
        $form['redirect_url'] = [
            OffsiteRedirect::TEXT_FIELD    => 'textfield',
            OffsiteRedirect::TITLE         => $this->t('Redirect Url'),
            OffsiteRedirect::DEFAULT_VALUE => $this->configuration['redirect_url'],
            '#required'                    => true,
        ];
        $form['test_url'] = [
            OffsiteRedirect::TEXT_FIELD    => 'textfield',
            OffsiteRedirect::TITLE         => $this->t('Test Url'),
            OffsiteRedirect::DEFAULT_VALUE => $this->configuration['test_url'],
            '#required'                    => true,
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values                              = $form_state->getValue($form['#parents']);
            $this->configuration['merchant_id']  = $values['merchant_id'];
            $this->configuration['partner_name']  = $values['partner_name'];
            $this->configuration['secret_key']  = $values['secret_key'];
            $this->configuration['redirect_url']  = $values['redirect_url'];
            $this->configuration['test_url'] = $values['test_url'];
        }
    }

    

    /**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request)
    {
        parent::onReturn($order, $request);
        Drupal::messenger()->addMessage('Thank you for placing your order');
    }

    /**
     * Get data array from a request content.
     *
     * @param string $request_content
     *   The Request content.
     *
     * @return array
     *   The request data array.
     */
    protected function getRequestDataArray(string $request_content): array
    {
        parse_str(html_entity_decode($request_content), $itn_data);

        return $itn_data;
    }

    /**
     * @param $remote_id
     *
     * @return false|mixed
     */
    protected function loadPaymentByRemoteId($remote_id): mixed
    {
        $message = '@message';
        /** @var PaymentStorage $storage */
        try {
            $storage = $this->entityTypeManager->getStorage('commerce_payment');
        } catch (Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException $e) {
            Drupal::logger('your_module')->error(
                'Invalid plugin definition exception: @message',
                [$message => $e->getMessage()]
            );
        } catch (Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
            Drupal::logger('your_module')->error(
                'Plugin not found exception: @message',
                [$message => $e->getMessage()]
            );
        }
        $payment_by_remote_id = $storage->loadByProperties(['order_id' => $remote_id]);

        return reset($payment_by_remote_id);
    }

    /**
     * @param Request $request
     *
     * @return void
     */
    public function onNotify(Request $request)
    {
        parent::onNotify($request);

        if ($this->getConfiguration()['debug'] === 1) {
            define("PF_DEBUG", true);
        }
        // Variable Initialization
        $pfError       = false;
        $pfErrMsg      = '';
        $pfData        = [];
        $pfHost        = ($this->configuration['test_url']);
        $pfParamString = '';

        Paymentdrupal_v10Common::pflog('Paymentdrupal_v10 ITN call received');

        // Notify Paymentdrupal_v10 that information has been received
        header('HTTP/1.0 200 OK');
        flush();

        // Get data sent by Paymentdrupal_v10
        Paymentdrupal_v10Common::pflog('Get posted data');

        // Posted variables from ITN
        $pfData = Paymentdrupal_v10Common::pfGetData();

        Paymentdrupal_v10Common::pflog('Paymentdrupal_v10 Data: ' . print_r($pfData, true));

        if ($pfData === false) {
            $pfError  = true;
            $pfErrMsg = Paymentdrupal_v10Common::PF_ERR_BAD_ACCESS;
        }

        // Verify security signature
        if (!$pfError) {
            Paymentdrupal_v10Common::pflog('Verify security signature');

            if ($this->getConfiguration()['passphrase'] === '') {
                $passphrase = null;
            } else {
                $passphrase = $this->getConfiguration()['passphrase'];
            }

            // If signature different, log for debugging
            if (!Paymentdrupal_v10Common::pfValidSignature($pfData, $pfParamString, $passphrase)) {
                $pfError  = true;
                $pfErrMsg = Paymentdrupal_v10Common::PF_ERR_INVALID_SIGNATURE;
            }
        }

        // Verify data received
        if (!$pfError) {
            Paymentdrupal_v10Common::pflog('Verify data received');

            $pfValid = Paymentdrupal_v10Common::pfValidData($pfHost, $pfParamString);

            if (!$pfValid) {
                $pfError  = true;
                $pfErrMsg = Paymentdrupal_v10Common::PF_ERR_BAD_ACCESS;
            }
        }

        $this->processOrder($pfError, $pfData, $pfErrMsg);
    }

    /**
     * Process the order
     *
     * @param $pfError
     * @param $pfData
     * @param $pfErrMsg
     *
     * @return void
     */
    public function processOrder($pfError, $pfData, $pfErrMsg): void
    {
        // Check status and update order
        if (!$pfError) {
            Paymentdrupal_v10Common::pflog('Check status and update order');

            if ($pfData['payment_status'] == 'COMPLETE') {
                $message = '@message';

                try {
                    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                } catch (Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException $e) {
                    Drupal::logger('your_module')->error(
                        'Invalid plugin definition exception: @message',
                        [$message => $e->getMessage()]
                    );
                } catch (Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
                    Drupal::logger('your_module')->error(
                        'Plugin not found exception: @message',
                        [$message => $e->getMessage()]
                    );
                }
                $payment = $payment_storage->create([
                                                        'state'           => 'completed',
                                                        'amount'          => $pfData['amount_gross'],
                                                        'payment_gateway' => $this->parentEntity->id(),
                                                        'order_id'        => $pfData['custom_int1'],
                                                        'remote_id'       => $pfData['pf_payment_id'],
                                                        'remote_state'    => $pfData['payment_status'],
                                                    ]);
                $payment->setRefundedAmount(new Price('0.00', 'ZAR'));
                $payment->setAmount(new Price($pfData['amount_gross'], 'ZAR'));
                try {
                    $payment->save();
                } catch (Drupal\Core\Entity\EntityStorageException $e) {
                    Drupal::logger('your_module')->error(
                        'Entity storage exception: @message',
                        [$message => $e->getMessage()]
                    );
                }
            }
        }

        if ($pfError) {
            Paymentdrupal_v10Common::pflog('Error: ' . $pfErrMsg);
        }
    }
}

