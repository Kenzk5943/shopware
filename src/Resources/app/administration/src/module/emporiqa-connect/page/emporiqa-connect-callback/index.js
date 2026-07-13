import template from './emporiqa-connect-callback.html.twig';
import './emporiqa-connect-callback.scss';

const { Component, Mixin } = Shopware;

Component.register('emporiqa-connect-callback', {
    template,

    inject: ['emporiqaService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            success: false,
            errorMessage: '',
            storeId: '',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    created() {
        const query = this.$route.query || {};
        const state = query.state;
        const code = query.code;

        if (!state || !code) {
            this.isLoading = false;
            this.errorMessage = this.$t('emporiqa-integration.connect.callback.missingParams');
            return;
        }

        this.emporiqaService.connectExchange(code, state)
            .then((response) => {
                if (response && response.success) {
                    this.success = true;
                    this.storeId = response.storeId || query.emporiqa_store_id || '';
                    if (typeof this.createNotificationSuccess === 'function') {
                        this.createNotificationSuccess({
                            message: this.$t('emporiqa-integration.connect.callback.successNotification'),
                        });
                    }
                } else {
                    this.errorMessage = (response && response.error)
                        || this.$t('emporiqa-integration.connect.callback.errorGeneric');
                }
            })
            .catch((error) => {
                this.errorMessage = error.response?.data?.error
                    || this.$t('emporiqa-integration.connect.callback.errorGeneric');
            })
            .finally(() => {
                this.isLoading = false;
            });
    },

    methods: {
        goToSync() {
            this.$router.push({
                name: 'emporiqa.integration.index',
                query: { connected: '1' },
            });
        },
        goToSettings() {
            this.$router.push({ name: 'emporiqa.integration.index' });
        },
    },
});
