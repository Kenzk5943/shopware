const { Component } = Shopware;

Component.override('sw-extension-config', {
    methods: {
        async createdComponent() {
            if (this.namespace === 'EmporiqaIntegration') {
                this.$router.replace({ name: 'emporiqa.integration.index' });
                return;
            }

            await this.$super('createdComponent');
        },
    },
});
