import EmporiqaApiService from '../core/service/api/emporiqa.api.service';

const { Application } = Shopware;

Application.addServiceProvider('emporiqaService', (container) => {
    const initContainer = Application.getContainer('init');

    return new EmporiqaApiService(initContainer.httpClient, container.loginService);
});
