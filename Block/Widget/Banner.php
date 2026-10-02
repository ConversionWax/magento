<?php
namespace Cwax\Banner\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Cwax\Banner\Model\BannerMarkup;

class Banner extends Template implements BlockInterface
{
protected $_template = "widget/banner.phtml";

    /**
     * Markup to output. In replace mode the placeholder wraps the original text (see BannerMarkup).
     */
    public function getBannerHtml(): string
    {
        return BannerMarkup::build(
            (string)$this->getData('banner_div'),
            (string)$this->getData('mode'),
            (string)$this->getData('original_html')
        );
    }
}
