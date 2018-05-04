<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Blacklight\libraries\Geary;
use Illuminate\Support\Facades\Auth;

class BtcPaymentController extends BasePageController
{
    /**
     * @param \Illuminate\Http\Request $request
     * @throws \Exception
     */
    public function show(Request $request)
    {
        $this->setPrefs();
        $gateway_id = env('MYCELIUM_GATEWAY_ID');
        $gateway_secret = env('MYCELIUM_GATEWAY_SECRET');

        $userId = Auth::id();
        $user = User::find($userId);
        $action = $request->input('action') ?? 'view';
        $donation = UserRole::query()->where('donation', '>', 0)->get(['id', 'name', 'donation', 'addyears']);
        $this->smarty->assign('donation', $donation);

        switch ($action) {
            case 'submit':
                $price = $request->input('price');
                $role = $request->input('role');
                $roleName = $request->input('rolename');
                $addYears = $request->input('addyears');
                $data = ['user_id' => $userId, 'username' => $user->username, 'price' => $price, 'role' => $role, 'rolename' => $roleName, 'addyears' => $addYears];
                $keychain_id = random_int(0, 19);
                $callback_data = json_encode($data);

                $geary = new Geary($gateway_id, $gateway_secret);
                $order = $geary->create_order($price, $keychain_id, $callback_data);

                if ($order->payment_id) {
                    // Redirect to a payment gateway
                    $url = 'https://gateway.gear.mycelium.com/pay/'.$order->payment_id;
                    header('Location: '.$url);
                    die();
                }
                break;
            case 'view':
            default:
                $userId = Auth::id();
                break;
        }

        $title = 'Become a supporter';
        $meta_title = 'Become a supporter';
        $meta_description = 'Become a supporter';

        $content = $this->smarty->fetch('btc_payment.tpl');

        $this->smarty->assign(
            [
                'content' => $content,
                'meta_title' => $meta_title,
                'title' => $title,
                'meta_description' => $meta_description,
            ]
        );
        $this->pagerender();
    }

    /**
     * Callback data from Mycelium Gear.
     */
    public function callback()
    {
        $gateway_id = env('MYCELIUM_GATEWAY_ID');
        $gateway_secret = env('MYCELIUM_GATEWAY_SECRET');

        $geary = new Geary($gateway_id, $gateway_secret);
        $order = $geary->check_order_callback();

        // Order status was received
        if ($order !== false) {
            $callback_data = json_decode($order['callback_data'], true);
            $newRole = $callback_data['role'];
            $amount = $callback_data['price'];
            $addYear = $callback_data['addyears'];
            // If order was paid in full (2) or overpaid (4)
            if ((int) $order['status'] === 2 || (int) $order['status'] === 4) {
                User::updateUserRole($callback_data['user_id'], $newRole);
                User::updateUserRoleChangeDate($callback_data['user_id'], Carbon::now()->addYears($addYear));
            }
        }
    }
}
