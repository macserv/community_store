<?php
namespace Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method;

use Doctrine\ORM\Mapping as ORM;
use Concrete\Core\Support\Facade\DatabaseORM as dbORM;
use Concrete\Core\Package\Package;
use Concrete\Core\View\View;
use Concrete\Core\Support\Facade\Session;
use Illuminate\Filesystem\Filesystem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodTypeMethod as StoreShippingMethodTypeMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodType as StoreShippingMethodType;

/**
 * @ORM\Entity
 * @ORM\Table(name="CommunityStoreShippingMethods")
 */
class ShippingMethod
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue **/
    protected $smID;

    /**
     * @ORM\Column(type="integer")
     */
    protected $smtID;

    /**
     * @ORM\Column(type="integer")
     */
    protected $smtmID;

    /**
     * @ORM\Column(type="string")
     */
    protected $smName;

    /**
     * @ORM\Column(type="text",nullable=true)
     */
    protected $smDetails;

    /**
     * @ORM\Column(type="integer")
     */
    protected $smEnabled;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $smSortOrder;

    protected $smOfferKey;

    public function setOfferKey($key)
    {
        $this->smOfferKey = $key;
    }

    public function getOfferKey()
    {
        if ($this->smOfferKey) {
            return $this->smOfferKey;
        } else {
            return 0;
        }
    }

    public function setShippingMethodTypeID($smt)
    {
        $this->smtID = $smt->getShippingMethodTypeID();
    }

    public function setShippingMethodTypeMethodID($smtm)
    {
        $this->smtmID = $smtm->getShippingMethodTypeMethodID();
    }

    public function setName($name)
    {
        $this->smName = $name;
    }

    public function setEnabled($status)
    {
        $this->smEnabled = $status;
    }

    public function setDetails($details)
    {
        $this->smDetails = $details;
    }

    public function setSortOrder($smSortOrder)
    {
        $this->smSortOrder = $smSortOrder;
    }

    public function getID()
    {
        return $this->smID;
    }

    public function getShippingMethodType()
    {
        return StoreShippingMethodType::getByID($this->smtID);
    }

    public function getShippingMethodTypeMethod()
    {
        $methodTypeController = $this->getShippingMethodType()->getMethodTypeController();
        $methodTypeMethod = $methodTypeController->getByID($this->smtmID);

        return $methodTypeMethod;
    }

    public function getOffers()
    {
        $offers = $this->getShippingMethodTypeMethod()->getOffers();
        $count = 0;

        foreach ($offers as $offer) {
            $offer->setMethodLabel($this->getName());
            $offer->setKey($this->getID() . '_' . $count++);
        }

        return $offers;
    }

    public function getCurrentOffer()
    {
        $currentOffers = $this->getOffers();

        if ($currentOffers && isset($currentOffers[$this->getOfferKey()])) {
            return $this->getOffers()[$this->getOfferKey()];
        } else {
            return null;
        }
    }

    public function getName()
    {
        return $this->smName;
    }

    public function getDetails()
    {
        return $this->smDetails;
    }

    public function isEnabled()
    {
        return $this->smEnabled;
    }

    public function getSortOrder()
    {
        return $this->smSortOrder;
    }

    public static function getByID($smID)
    {
        $ident = explode('_', $smID);
        $smID = $ident[0];

        $em = dbORM::entityManager();
        $method = $em->find(get_called_class(), $smID);

        if ($method) {
            if (isset($ident[1])) {
                $method->setOfferKey($ident[1]);
            }

            return $method;
        }

        return false;
    }

    public static function getAvailableMethods($methodTypeID = null)
    {
        $em = dbORM::entityManager();
        if ($methodTypeID) {
            $methods = $em->getRepository(get_called_class())->findBy(['smtID' => $methodTypeID, 'smEnabled' => '1']);
        } else {
            $methods = $em->createQuery('select sm from \Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethod sm where sm.smEnabled = 1 order by sm.smSortOrder')->getResult();
        }

        return $methods;
    }

    public static function getMethods($methodTypeID = null)
    {
        $em = dbORM::entityManager();
        if ($methodTypeID) {
            $methods = $em->getRepository(get_called_class())->findBy(['smtID' => $methodTypeID]);
        } else {
            $methods = $em->createQuery('select sm from \Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethod sm')->getResult();
        }

        return $methods;
    }

    /**
     * @ORM\param StoreShippingMethodTypeMethod $smtm
     * @ORM\param StoreShippingMethodType $smt
     * @ORM\param string $smName
     * @ORM\param bool $smEnabled
     *
     * @ORM\return ShippingMethod
     */
    public static function add($smtm, $smt, $smName, $smEnabled, $smDetails, $smSortOrder)
    {
        $sm = new self();
        $sm->setShippingMethodTypeMethodID($smtm);
        $sm->setShippingMethodTypeID($smt);
        $sm->setName($smName);
        $sm->setEnabled($smEnabled);
        $sm->setDetails($smDetails);
        $sm->setSortOrder($smSortOrder);
        $sm->save();
        $smtm->setShippingMethodID($sm->getID());
        $smtm->save();

        return $sm;
    }

    public function update($smName, $smEnabled, $smDetails, $smSortOrder)
    {
        $this->setName($smName);
        $this->setEnabled($smEnabled);
        $this->setSortOrder($smSortOrder);
        $this->setDetails($smDetails);
        $this->save();

        return $this;
    }

    public function save()
    {
        $em = dbORM::entityManager();
        $em->persist($this);
        $em->flush();
    }

    public function delete()
    {
        $this->getShippingMethodTypeMethod()->delete();
        $em = dbORM::entityManager();
        $em->remove($this);
        $em->flush();
    }

    public static function getEligibleMethods()
    {
        $allMethods = self::getAvailableMethods();
        $eligibleMethods = [];
        foreach ($allMethods as $method) {
            if ($method->getShippingMethodTypeMethod()->isEligible()) {
                $eligibleMethods[] = $method;
            }
        }

        return $eligibleMethods;
    }

    public function getShippingMethodSelector()
    {
        if (Filesystem::exists(DIR_BASE . "/application/elements/checkout/shipping_methods.php")) {
            View::element("checkout/shipping_methods");
        } elseif (Filesystem::exists(DIR_BASE . "/packages/" . $this->getPackageHandle() . "/elements/checkout/shipping_methods.php")) {
            View::element("checkout/shipping_methods", $this, $this->getPackageHandle());
        } else {
            View::element("checkout/shipping_methods", "community_store");
        }
    }

    public static function getActiveShippingMethod()
    {
        $smID = Session::get('community_store.smID');
        if ($smID) {
            $sm = self::getByID($smID);

            return $sm;
        }
    }

    public static function getActiveShippingLabel()
    {
        $activeShippingMethod = self::getActiveShippingMethod();

        if ($activeShippingMethod) {
            $currentOffer = $activeShippingMethod->getCurrentOffer();
            if ($currentOffer) {
                return $currentOffer->getLabel();
            }
        }

        return '';
    }

    public static function getActiveShipmentID()
    {
        $activeShippingMethod = self::getActiveShippingMethod();

        if ($activeShippingMethod) {
            $currentOffer = $activeShippingMethod->getCurrentOffer();
            if ($currentOffer) {
                return $currentOffer->getShipmentID();
            }
        }

        return '';
    }

    public static function getActiveRateID()
    {
        $activeShippingMethod = self::getActiveShippingMethod();

        if ($activeShippingMethod) {
            $currentOffer = $activeShippingMethod->getCurrentOffer();
            if ($currentOffer) {
                return $currentOffer->getRateID();
            }
        }

        return '';
    }

    public function getPackageHandle()
    {
        return Package::getByID($this->getShippingMethodType()->getPackageID())->getPackageHandle();
    }
}
