<?php

namespace App\Http\Controllers\gentleman;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Helpers\CustomHelper;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

use App\GiftsCategory;
use App\GiftsProduct;
use App\GiftsOrder;
use App\Payments;
use App\Messages;
use App\WomenBasic;

use App\Events\NewMessageSend;

class ShoppingController extends Controller
{
    private $gentleman;

    private $user;

    public function __construct()
    {
        $user = Auth::user();
        $this->user = $user;
        $this->gentleman = $user->gentleman;
    }

    public function listGet($id = false, $data = [])
    {
        $receiver = WomenBasic::where('user_id', $id)->first();

        abort_if(empty($receiver), 404);

        $gentleman = $this->gentleman;
        $categories = GiftsCategory::active()->featured()->orderFeatured()->get()->each(function($value) {
            $value->description = str_limit($value->description, 100);
        });
        if (count($categories)) {
            $products = $categories->first()->getActiveProductsPaginate();
        }
        $cart = $this->user->getCart();
        return compact('gentleman', 'categories', 'products', 'cart', 'receiver');
    }

    public function getCatsActiveProductsAjax($id = false, $data = [])
    {
        $data = array_only($data, ['catId','numPage']);
        $id = $data['catId'];
        $numPage = ! empty($data['numPage']) ? $data['numPage'] : 1;
        $category = GiftsCategory::find($data['catId']);
        $items = $category->getActiveProductsPaginate($numPage);
        $cart = $this->user->getCart();
        return view('gentleman.shopping.product-list', compact('items', 'cart'));
    }

    public function getProdDetailInfoAjax($id = false, $data = [])
    {
        $data = array_only($data, ['prodId']);
        $item = GiftsProduct::with('photos')->find($data['prodId']);
        return view('gentleman.shopping.product-detail', compact('item'));
    }

    public function addToCartAjax($id = false, $data = [])
    {
        $data = array_only($data, ['prodId', 'quantity']);
        $this->user->addProductToCart($data['prodId'], $data['quantity']);
        return ['cart' => $this->user->getCart()];
    }

    public function removeFromCartAjax($id = false, $data = [])
    {
        $data = array_only($data, ['prodId']);
        $this->user->removeProductFromCart($data['prodId']);
        return ['cart' => $this->user->getCart()];
    }

    public function getCartViewAjax($id = false, $data = [])
    {
        $cart = $this->user->getCart();
        return view('gentleman.shopping.cart', compact('cart'));
    }

    public function plusProductInCartAjax($id = false, $data = [])
    {
        $data = array_only($data, ['prodId']);
        $this->user->plusProductInCart($data['prodId']);
        return ['success' => true];
    }

    public function minusProductInCartAjax($id = false, $data = [])
    {
        $data = array_only($data, ['prodId']);
        $this->user->minusProductInCart($data['prodId']);
        return ['success' => true];
    }

    public function findReceiverAjax($id = false, $data = [])
    {
        $data = array_only($data, ['term']);
        $search = $data['term'];
        $items = WomenBasic::select('id', 'user_id', 'fname', 'lname')->where('status', 1)->where(function($q) use ($search){
            $q->orWhere('fname','like','%'.$search.'%');
            $q->orWhere('lname','like','%'.$search.'%');
            $q->orWhereHas('user', function($q) use($search){
                $q->where('name','like','%'.$search.'%');
            });
        })->get();
        $items->each(function($value) {
            $value->value = $value->fname.' '.$value->fname.' ('.$value->user->name.')';
            $value->user_id = $value->user->id;
        });

        if ( ! count($items)) {
            $items[] = ['label' => 'Not found', 'value' => ' ', 'user_id' => null];
        }

        return $items;
    }

    public function createOrderAjax($id = false, $data = [])
    {
        $data = array_only($data, ['receiver_user_id', 'city', 'state', 'postal','phone', 'description', 'message_text']);
        $cart = $this->user->getCart();
        if ($cart['countItems'] == 0) {
            $result = [
                'success' => false,
                'message' => ['cart' => ['You have no items in cart']]
            ];
            return $result;
        }
        $rules = [
            'receiver_user_id' => 'required',
            'description' => 'required',
            'message_text' => 'nullable|max:255',
        ];
        $messages = [
            'receiver_user_id.required' => 'Choose receiver'
        ];
        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => $validator->getMessageBag()->toArray(),
            ];
        } else {
            $cart = $this->user->getCart();
            if ($this->gentleman->credits < $cart['cartTotal']) {
                $validator->errors()->add('field', 'Insufficient funds');
                return [
                    'success' => false,
                    'showPaymentModal' => true,
                    'message' => $validator->getMessageBag()->toArray(),
                ];
            }
            $data['status'] = 0;
            $data['total'] = $cart['cartTotal'];
            $data['user_id'] = $this->user->id;
            $newOrder = GiftsOrder::create($data);
            foreach($cart['items'] as $item) {
                $newOrderItem['product_code'] = $item->code;
                $newOrderItem['name'] = $item->name;
                $newOrderItem['description'] = $item->description;
                $newOrderItem['price'] = $item->price;
                $newOrderItem['credits'] = $item->credits;
                $newOrderItem['category'] = $item->category->name;
                $newOrderItem['quantity'] = $item->pivot->quantity;
                $newOrderItem['product_id'] = $item->id;
                $newOrder->items()->create($newOrderItem);
            }
            $newOrder->setTotalCredits();
            $newOrder->save();

            if ( ! empty($data['message_text'])) {
                $newOrder->message_id = $this->sendMessage($newOrder, $data['message_text']);
                $newOrder->save();
            }

            $this->user->emptyCart();

            $credits = PaymentController::withdrawCreditsForShop($newOrder);

            return [
                'success' => true,
                'showPaymentModal' => false,
                'message' => 'Order created.',
                'сreditsRemained' => ! empty($credits) ? number_format($credits, 2) : false,
                'cart' => $this->user->getCart()
            ];
        }
    }

    private function sendMessage(GiftsOrder $order, $text = '')
    {
        $message = new Messages;
        $message->from = $order->user_id;
        $message->to = $order->receiver_user_id;
        $message->from_type = 'g';
        $message->text = nl2br($text);
        $message->save();

        event(new NewMessageSend($message));

        return $message->id;
    }

}
