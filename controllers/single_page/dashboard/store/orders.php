<?php
namespace Concrete\Package\CommunityStore\Controller\SinglePage\Dashboard\Store;

use Concrete\Core\User\User;
use Concrete\Core\View\View;
use Concrete\Core\Http\Request;
use Concrete\Core\Routing\Redirect;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Search\Pagination\PaginationFactory;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Package\CommunityStore\Entity\Attribute\Key\StoreOrderKey;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order as StoreOrder;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList as StoreOrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus as StoreOrderStatus;

class Orders extends DashboardPageController
{
    public function view($status = 'all', $paymentMethod = 'all', $paymentStatus = 'all')
    {

        $statusFilter = $status;
        $paymentMethodFilter  = $paymentMethod;
        $paymentStatusFilter = $paymentStatus;

        if ($status == 'all') {
            $statusFilter = '';
        }

        if ($paymentMethod == 'all') {
            $paymentMethodFilter = '';
        }

        if ($paymentStatus == 'all') {
            $paymentStatusFilter = '';
        }

        $orderList = new StoreOrderList();

        if ($this->request->query->get('keywords')) {
            $orderList->setSearch($this->request->query->get('keywords'));
            $this->set('keywords', $this->request->query->get('keywords'));
        }

        if ($statusFilter) {
            $orderList->setStatus($statusFilter);
        }

        if ($paymentMethodFilter) {
            $orderList->setPaymentMethods($paymentMethodFilter);
        }

        if ($paymentStatusFilter) {
            $orderList->setPaymentStatus($paymentStatusFilter);
        }

        if (Config::get('community_store.numberOfOrders')) {
            $orderList->setItemsPerPage(Config::get('community_store.numberOfOrders'));
        } else {
            $orderList->setItemsPerPage(20);
        }


        if (Config::get('community_store.showUnpaidExternalPaymentOrders')) {
            $orderList->setIncludeExternalPaymentRequested(true);
        }

        $factory = new PaginationFactory($this->app->make(Request::class));
        $paginator = $factory->createPaginationObject($orderList);
        $enabledMethods = StorePaymentMethod::getEnabledMethods();

        $pagination = $paginator->renderDefaultView();
        $this->set('orderList', $paginator->getCurrentPageResults());
        $this->set('pagination', $pagination);
        $this->set('paginator', $paginator);
        $this->set('orderStatuses', StoreOrderStatus::getList());
        $this->set('status', $status);
        $this->requireAsset('css', 'communityStoreDashboard');
        $this->requireAsset('javascript', 'communityStoreFunctions');
        $this->set('statuses', StoreOrderStatus::getAll());
        $this->set('paymentMethod', $paymentMethod);
        $this->set("enabledPaymentMethods", $enabledMethods);
        $this->set('paymentStatus', $paymentStatus);

        if ('all' == Config::get('community_store.shoppingDisabled')) {
            $this->set('shoppingDisabled', true);
        }
        $this->set('pageTitle', t('Orders'));
    }

    public function order($oID)
    {
        $order = StoreOrder::getByID($oID);

        if ($order) {
            $this->set("order", $order);
            $this->set('orderStatuses', StoreOrderStatus::getList());
            $orderChoicesAttList = StoreOrderKey::getAttributeListBySet('order_choices');
            if (is_array($orderChoicesAttList) && !empty($orderChoicesAttList)) {
                $this->set("orderChoicesAttList", $orderChoicesAttList);
            } else {
                $this->set("orderChoicesAttList", []);
            }
            $this->requireAsset('javascript', 'communityStoreFunctions');
        } else {
            return Redirect::to('/dashboard/store/orders');
        }

        $this->set('pageTitle', t("Order #") . $order->getOrderID());
    }

    public function updatestatus($oID)
    {
        $data = $this->request->request->all();
        if ($this->token->validate('community_store')) {
            StoreOrder::getByID($oID)->updateStatus($data['orderStatus']);
            $this->flash('success', t('Fulfilment Status Updated'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function markpaid($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            if ($this->request->request->get('transactionReference')) {
                $order->setTransactionReference($this->request->request->get('transactionReference'));
            }

            $user = new User();

            $order->completePayment();
            $order->setExternalPaymentRequested(null);
            $order->setPaidByUID($user->getUserID());
            $order->save();

            $this->flash('success', t('Order Marked As Paid'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function reversepaid($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $order->setPaid(null);
            $order->setPaidByUID(null);
            $order->setTransactionReference(null);
            $order->save();

            $this->flash('success', t('Order Payment Reversed'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function markrefunded($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $user = new User();

            $order->setRefunded(new \DateTime());
            $order->setRefundedByUID($user->getUserID());
            $order->setRefundReason($this->request->request->get('oRefundReason'));
            $order->save();

            $this->flash('success', t('Order Marked As Refunded'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function reverserefund($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $order->setRefunded(null);
            $order->setRefundedByUID(null);
            $order->setRefundReason(null);
            $order->save();

            $this->flash('success', t('Order Refund Reversed'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function markcancelled($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $user = new User();

            $order->setCancelled(new \DateTime());
            $order->setCancelledByUID($user->getUserID());
            $order->save();

            $this->flash('success', t('Order Cancelled'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function reversecancel($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $order->setCancelled(null);
            $order->setCancelledByUID(null);
            $order->save();

            $this->flash('success', t('Order Cancellation Reversed'));

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function resendinvoice($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);

            if ($order && $this->request->request->get('email')) {
                $order->sendOrderReceipt($this->request->request->get('email'));
                $this->flash('success', t('Receipt Email resent to %s', $this->request->request->get('email')));
            }

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function resendnotification($oID)
    {
        if ($this->token->validate('community_store')) {
            $order = StoreOrder::getByID($oID);
            $emails = $this->request->request->get('email');

            if ($order && $emails) {
                $order->sendNotifications($this->request->request->get('email'));
                $notificationEmails = explode(",", trim($emails));
                $notificationEmails = array_map('trim', $notificationEmails);
                $this->flash('success', t('Notification Email resent to %s', implode(', ', $notificationEmails)));
            }

            return Redirect::to('/dashboard/store/orders/order', $oID);
        }
    }

    public function remove($oID)
    {
        if ($this->token->validate('community_store')) {
            StoreOrder::getByID($oID)->remove();
            $this->flash('success', t('Order Deleted'));
        }

        return Redirect::to('/dashboard/store/orders');
    }

    public function printslip($oID)
    {
        $o = StoreOrder::getByID($oID);
        $orderChoicesAttList = StoreOrderKey::getAttributeListBySet('order_choices', User::getByUserID($o->getCustomerID()));

        if (\Illuminate\Filesystem\Filesystem::exists(DIR_BASE . "/application/elements/order_slip.php")) {
            View::element("order_slip", ['order' => $o, 'orderChoicesAttList' => $orderChoicesAttList]);
        } else {
            View::element("order_slip", ['order' => $o, 'orderChoicesAttList' => $orderChoicesAttList], "community_store");
        }

        exit();
    }
}
