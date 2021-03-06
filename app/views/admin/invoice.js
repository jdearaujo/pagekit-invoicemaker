module.exports = {

    el: '#invoice-edit',

    data() {
        return _.merge({
            invoice: {
                debtor: {},
                invoice_lines: [],
                payments: [],
                data: {},
            },
            statuses: [],
            templates: [],
            form: {}
        }, window.$data);
    },

    created() {
        if (!_.isArray(this.invoice.payments)) {
            this.invoice.payments = [];
        }
        this.invoice.amount = Number(this.invoice.amount);
        this.invoice.amount_paid = Number(this.invoice.amount_paid);
    },

    ready() {
        this.Invoices = this.$resource('api/invoicemaker/invoice{/id}', {}, {
            'rerender': {method: 'get', url: 'api/invoicemaker/invoice/rerender{/id}'},
            'credit': {method: 'post', url: 'api/invoicemaker/invoice/credit{/id}'},
        });
    },

    computed: {
        keys() {
            return (this.types[this.invoice.type] ? this.types[this.invoice.type].keys : []);
        }
    },

    methods: {

        save() {
            this.Invoices.save({id: this.invoice.id}, {invoice: this.invoice}).then(res => {
                var data = res.data;
                if (!this.invoice.id) {
                    window.history.replaceState({}, '', this.$url.route('admin/invoicemaker/invoice/edit', {id: data.invoice.id}));
                }

                this.$set('invoice', data.invoice);

                this.$notify(this.$trans('Invoice %invoice_number% saved.', {invoice_number: this.invoice.invoice_number}));

                this.$els.iframe.contentWindow.location.reload();

            }, res => {
                this.$notify(res.data || res, 'danger');
            });
        },

        rerender() {
            this.Invoices.rerender({id: this.invoice.id}).then(() => {
                this.$notify('PDF rerendered.', 'success');
            }, res => {
                this.$notify(res.data || res, 'danger');
            });
        },

        credit() {
            this.Invoices.credit({id: this.invoice.id}, {}).then((res) => {
                this.$notify(this.$trans('Credit invoice %invoice_number% created.', {
                    'invoice_number': res.data.invoice.invoice_number
                }), 'success');
            }, res => {
                this.$notify(res.data.message || res.data, 'danger');
            });
        },

        getStatusText(status) {
            return this.statuses[status] || status;
        }

    },

    components: {
        'invoice-payments': require('../../components/invoice-payments.vue'),
    },

};

Vue.ready(module.exports);
