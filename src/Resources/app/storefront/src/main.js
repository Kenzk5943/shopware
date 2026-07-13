import EmporiqaWidgetPlugin from './emporiqa-widget/emporiqa-widget.plugin';
import EmporiqaCartPlugin from './emporiqa-cart/emporiqa-cart.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('EmporiqaWidget', EmporiqaWidgetPlugin, '[data-emporiqa-widget]');
PluginManager.register('EmporiqaCart', EmporiqaCartPlugin, '[data-emporiqa-cart]');
