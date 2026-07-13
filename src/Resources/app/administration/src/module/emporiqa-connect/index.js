import './page/emporiqa-connect-callback';

const { Module } = Shopware;

// Separate module so the callback route resolves to /admin#/emporiqa/connect/callback
// (route name emporiqa.connect.callback), the exact path Emporiqa redirects back to.
Module.register('emporiqa-connect', {
    type: 'plugin',
    name: 'emporiqa-connect',
    title: 'emporiqa-integration.connect.callback.title',
    description: 'emporiqa-integration.connect.callback.title',
    color: '#1a73e8',
    icon: 'regular-plug',

    routes: {
        callback: {
            component: 'emporiqa-connect-callback',
            path: 'callback',
        },
    },
});
