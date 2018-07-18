<?php

namespace App\Http\Controllers\gentleman;

use App\Http\Controllers\Controller;
use App\Gentlemen;
use App\Payments;
use Illuminate\Support\Facades\Auth;
use Validator;
use Paypalpayment;
use Carbon\Carbon;
use App\DatesType;
use App\DatesItem;
use Illuminate\Support\Facades\Mail;
use App\Mail\GentlPaymentAddCreditsSuccess;
use App\GiftsOrder;
use App\WomenBasic;
use Cardinity\Client;
use Cardinity\Method\Payment;
use Cardinity\Exception;
use App\User;

class PaymentController extends Controller
{
    private $gentleman;

    private $_apiContext;

    private $plans;

    private $price;

    public function __construct() {
        $user = Auth::user();
        $this->gentleman = $user->gentleman;
        $this->_apiContext = Paypalpayment::ApiContext(config('paypal_payment.Account.ClientId'), config('paypal_payment.Account.ClientSecret'));
        $this->plans = [
            1 => ['amount' => 15.99, 'credits' => 20],
            2 => ['amount' => 49.99, 'credits' => 80],
            3 => ['amount' => 79.99, 'credits' => 160],
            4 => ['amount' => 399.99, 'credits' => 1200],
        ];
    }

    public function getPlanAjax($id = false, $data = [])
    {
        return ! empty($this->plans[$data['planId']]) ? $this->plans[$data['planId']] : false;
    }

    public function sendPaypalInfoAjax($id = false, $data = [])
    {
        if (count($data['data'])) {
            $payment = new Payments;
            $payment->gentlemen_user_id = $this->gentleman->user_id;
            $payment->type = 'in';
            $payment->payment_id = $data['data']['paymentID'];
            $payment->payer_id = $data['data']['payerID'];
            $payment->payment_token = $data['data']['paymentToken'];
            $payment->save();
            $paypalInfo = $this->getPayPalById($payment->payment_id);
            $amount = 0;
            foreach ($paypalInfo->transactions as $item) {
                $amount =+ $item->amount->total;
            }
            $payment->amount = $amount;
            $payment->credits = $this->getCreditsByAmount($amount);
            $payment->payment_state = $paypalInfo->state;
            $payment->payment_method = $paypalInfo->payer->payment_method;
            $payment->save();

            if ($payment->payment_state == 'approved') {
                $gentleman = $this->gentleman;
                $gentleman->credits += $payment->credits;
                $gentleman->save();
                
                if ($gentleman->user->verified) {
                    Mail::to($gentleman->email)->queue(new GentlPaymentAddCreditsSuccess($payment));
                }

                return [
                    'success' => true,
                    'credits' => $payment->credits,
                    'message' => 'Your purchase has been successful!'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error'
                ];
            }
        }
    }

    public static function getPriceMessage($data)
    {
        $price = 10;
        if ( ! empty($data['pics']) && count($data['pics'])) {
            $pics = array_slice($data['pics'], 1);
            foreach($pics as $item) {
                $price += 10;
            }
        }
        return $price;
    }

    public static function getPriceReadMessage($message)
    {
        $price = 10;
        //if it's first reply for love note
        
        // if ( ! empty($message->love_note_id) && ! empty($message->replyMesage) && empty($message->replyMesage->message_id) ) {
        //     $price = 0;
        // }

        // free intletter
        if ($message->intletter) {
            $price = 0;
        }

        return $price;
    }

    public static function getPriceHug()
    {
        return 1;
    }

    public static function getPriceKiss()
    {
        return 2;
    }

    public static function getPriceDate(DatesType $type)
    {
        return $type->price;
    }

    public static function withdrawCreditsForMessage ($message, $price) {
        if ( ! $price) {
            return 0;
        }
        
        $gentleman = $message->gSender;
        $women = $message->wReceiver;
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 1;
        $payment->women_user_id = $message->wReceiver->user_id;
        // $payment->matchmaker_user_id = $message->wReceiver->matchmaker->user_id;
        $payment->details = 'from '.$gentleman->user->name.' to '.$women->user->name;
        $payment->credits = $price;
        $payment->save();
        $gentleman->credits -= $price;
        $gentleman->save();
        self::addCreditsForMatchmaker($payment);
        return $gentleman->credits;
    }

    public static function withdrawCreditsForReadMessage ($message) {
        $gentleman = $message->gReceiver;
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 2;
        $payment->women_user_id = $message->wSender->user_id;
        // $payment->matchmaker_user_id = $message->wSender->matchmaker->user_id;
        $payment->credits = 10;
        $payment->save();
        $gentleman->credits -= 10;
        $gentleman->save();
        self::addCreditsForMatchmaker($payment);
        return $gentleman->credits;
    }

    public static function withdrawCreditsForRequestAccessMessagePics($gentleman, $receiver, $price) {
        
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 20;
        $payment->details =  'from ' . $gentleman->user->name . ' to ' . User::find($receiver)->name;
        $payment->women_user_id = $receiver;
        $payment->credits = $price;
        $payment->save();

        $gentleman->credits -= $price;
        $gentleman->save();

        self::addCreditsForMatchmaker($payment);
        return $gentleman->credits;
    }

    public static function withdrawCreditsForHug($gentleman, $women, $price)
    {
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 8;
        $payment->women_user_id = $women->user_id;
        // $payment->matchmaker_user_id = $women->matchmaker->user_id;
        $payment->credits = $price;
        $payment->details = 'from '.$gentleman->user->name.' to '.$women->user->name;
        $payment->save();
        $gentleman->credits -= $price;
        $gentleman->save();
        self::addCreditsForMatchmaker($payment);
        return $gentleman->credits;
    }

    public static function withdrawCreditsForKiss($gentleman, $women, $price)
    {
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 9;
        $payment->women_user_id = $women->user_id;
        // $payment->matchmaker_user_id = $women->matchmaker->user_id;
        $payment->credits = $price;
        $payment->details = 'from '.$gentleman->user->name.' to '.$women->user->name;
        $payment->save();
        $gentleman->credits -= $price;
        $gentleman->save();
        self::addCreditsForMatchmaker($payment);
        return $gentleman->credits;
    }

    public static function withdrawCreditsForDate($gentleman, DatesItem $item, $price)
    {
        $women = $item->receiver;
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 12;
        $payment->women_user_id = $women->user_id;
        // $payment->matchmaker_user_id = $women->matchmaker->user_id;
        $payment->credits = $price;
        $payment->details = 'from '.$gentleman->user->name.' to '.$women->user->name;
        $payment->save();
        $gentleman->credits -= $price;
        $gentleman->save();
        return $gentleman->credits;
    }

    public static function addCreditsForCancelDate($gentleman, DatesItem $item)
    {
        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'add';
        $payment->for = 13;
        $payment->credits = $item->price;
        $payment->save();
        $gentleman->credits += $item->price;
        $gentleman->save();
        return $gentleman->credits;
    }

    public static function withdrawCreditsForShop(GiftsOrder $order)
    {
        $women = $order->receiver->women;
        $gentleman = $order->sender->gentleman;

        $payment = new Payments;
        $payment->gentlemen_user_id = $gentleman->user_id;
        $payment->type = 'out';
        $payment->for = 15;
        $payment->women_user_id = $women->user_id;
        // $payment->matchmaker_user_id = $women->matchmaker->user_id;
        $payment->credits = $order->total;
        $payment->details = 'from '.$gentleman->user->name.' to '.$women->user->name;
        $payment->save();

        $gentleman->credits -= $order->total;
        $gentleman->save();

        return $gentleman->credits;
    }


    public function loadCreditsRemainedAjax()
    {
        return $this->gentleman->credits;
    }

    private static function addCreditsForMatchmaker($payment)
    {
        $summ = $payment->credits;
        $matchmaker = $payment->women->matchmaker;

        $matchPayment = new Payments;
        $matchPayment->type = 'in';
        $matchPayment->matchmaker_user_id = $matchmaker->user_id;
        // $matchPayment->women_user_id = $payment->women_user_id;
        $matchPayment->for = $payment->for;
        $matchPayment->credits = $payment->credits;
        $matchPayment->details = ! empty($payment->details) ? $payment->details : '';
        $matchPayment->save();

        $resipient = $matchmaker->matchmaker_role == 1 ? $matchmaker->assignMatchmaker : $matchmaker;
        $resipient->credits += $summ;
        $resipient->save();
    }

    private function getPayPalById($payPayId)
    {
        $payment = Paypalpayment::getById($payPayId, $this->_apiContext);
        return $payment;
    }

    private function getCreditsByAmount($amount)
    {
        $credits = 0;
        foreach ($this->plans as $plan) {
            if ($plan['amount'] == $amount) {
                $credits = $plan['credits'];
            }
        }
        return $credits;
    }

    public function sendCardinityInfoAjax($id = false, $data = [])
    {
        if (!empty($data['plan_id']) && count($data['card'])) {
            // store payment in database
            
            $plan = isset($this->plans[$data['plan_id']])
                ? $this->plans[$data['plan_id']]
                : $this->plans[4];
            
            $payment = new Payments();
            $payment->gentlemen_user_id = $this->gentleman->user_id;
            $payment->credits = $plan['credits'];
            $payment->amount = $plan['amount'];
            $payment->type = 'in';
            $payment->save();
            
            $method = new Payment\Create([
                'amount' => (float) $plan['amount'], 
                'currency' => 'EUR',
                'order_id' => $payment->code,
                'country' => $this->gentleman->country_living_name->iso,
                // 'description' => '3d-fail', // '3d-pass'
                'payment_method' => Payment\Create::CARD,
                'payment_instrument' => [
                    'pan' => $data['card']['pan'],
                    'exp_year' => (int) $data['card']['exp_year'],
                    'exp_month' => (int) $data['card']['exp_month'],
                    'cvc' => $data['card']['cvc'],
                    'holder' => $data['card']['holder'],
                ],
            ]);
            
            return $this->cardinitySend($method);
        }
    }

    public function makeThreeDForm($data)
    {
        return '<form name="ThreeDForm" method="POST" action="'.$data['actionUrl'].'">'.
            '<input type="hidden" name="PaReq" value="'.$data['paReq'].'" />'.
            '<input type="hidden" name="TermUrl" value="'.$data['callbackUrl'].'" />'.
            '<input type="hidden" name="MD" value="'.$data['md'].'" />'.
            '<button type=submit>Click Here</button>'.
        '</form>';
    }

    public function cardinitySend($method)
    {
        $client = Client::create([
            'consumerKey' => config('cardinity_payment.consumerKey'),
            'consumerSecret' => config('cardinity_payment.consumerSecret'),
        ]);

        try {
            $paymentResult = $client->call($method);
            $status = $paymentResult->getStatus();

            $payment = Payments::where('code', $paymentResult->getOrderId())->first();
            $payment->payment_id = $paymentResult->getId();
            $payment->payment_method = $paymentResult->getType();
            $payment->save();

            if ($status == 'approved') {
                $payment->payment_state = $paymentResult->getStatus();
                $payment->save();

                $gentleman = $this->gentleman;
                $gentleman->credits += $payment->credits;
                $gentleman->save();

                if ($gentleman->user->verified) {
                    Mail::to($gentleman->email)->queue(new GentlPaymentAddCreditsSuccess($payment));
                }

                return [
                    'success' => true,
                    'status' => $status,
                    'credits' => $payment->credits,
                    'message' => 'Your purchase has been successful!'
                ];
            }
            elseif ($status == 'pending') {
                $payment->payment_state = $paymentResult->getStatus();
                $payment->save();

                $auth = $paymentResult->getAuthorizationInformation();
                
                $threeDFormData = [
                    'actionUrl' => $auth->getUrl(),
                    'paReq' => $auth->getData(),
                    'callbackUrl' => url('gentleman/cardinityCallback'),
                    'md' => $paymentResult->getOrderId(),
                ];
                
                return [
                    'success' => true,
                    'status' => $status,
                    'message' => 'You will be redirected for payment confirmation.',
                    'three_d_form' => $this->makeThreeDForm($threeDFormData)
                ];
            }
        } catch (Exception\InvalidAttributeValue $exception) {
            // $errors = [];

            // foreach ($exception->getViolations() as $key => $violation) {
            //     array_push($errors, $violation->getMessage());
            // }
            
            return [
                'success' => false,
                'message' => 'Validation error',
                // 'errors' => $errors
            ];
        } catch (Exception\ValidationFailed $exception) {
            $paymentResult = $exception->getResult();

            return [
                'success' => false,
                'message' => $paymentResult->getTitle(),
                // 'errors' => $exception->getErrorsAsString()
            ];
        } catch (Exception\Declined $exception) {
            $paymentResult = $exception->getResult();
            $errors = $exception->getErrors();

            $payment = Payments::where('code', $paymentResult->getOrderId())->first();

            $payment->payment_state = $paymentResult->getStatus();
            $payment->save();

            return [
                'success' => false,
                'message' => $paymentResult->getError(),
                // 'cardinityPayment' => $paymentResult,
                // 'status' => $status,
                // 'errors' => $errors,
            ];
        }
    }

    public function cardinityCallback($data = [])
    {
        if (isset($data['MD']) && isset($data['PaRes'])) {
            $payment = Payments::where('code', $data['MD'])->first();

            $method = new Payment\Finalize(
                $payment->payment_id,
                $data['PaRes']
            );

            return $this->cardinitySend($method);
        }
    }

}