<v-charts-line {{ $attributes }}></v-charts-line>

@pushOnce('scripts')
    <!-- SEO Vue Component Template -->
    <script
        type="text/x-template"
        id="v-charts-line-template"
    >
        <canvas
            :id="$.uid + '_chart'"
            class="flex w-full items-end"
            :style="'aspect-ratio:' + aspectRatio + '/1'"
        ></canvas>
    </script>

    <script type="module">
        app.component('v-charts-line', {
            template: '#v-charts-line-template',

            props: {
                labels: {
                    type: Array, 
                    default: [],
                },

                datasets: {
                    type: Array, 
                    default: true,
                },

                aspectRatio: {
                    type: Number, 
                    default: 3.23,
                },
            },

            data() {
                return {
                    chart: undefined,
                }
            },

            mounted() {
                this.prepare();
            },

            beforeUnmount() {
                // Destruir la instancia al desmontar evita que Chart.js siga su loop de
                // dibujado sobre un canvas ya removido del DOM (getContext sobre null).
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = undefined;
                }
            },

            methods: {
                prepare() {
                    if (this.chart) {
                        this.chart.destroy();
                    }

                    const canvas = document.getElementById(this.$.uid + '_chart');

                    if (! canvas) {
                        return;
                    }

                    // Mata cualquier instancia "zombie" aún ligada a este canvas antes
                    // de crear una nueva (evita getContext sobre null en el loop de dibujado).
                    Chart.getChart(canvas)?.destroy();

                    this.chart = new Chart(canvas, {
                        type: 'line',
                        
                        data: {
                            labels: this.labels,

                            datasets: this.datasets,
                        },
                
                        options: {
                            aspectRatio: this.aspectRatio,

                            // Sin animación: el chart no entra al Animator de Chart.js, evitando
                            // que su loop dibuje sobre un canvas ya destruido (getContext de null).
                            animation: false,

                            plugins: {
                                legend: {
                                    display: false
                                },

                                {{-- tooltip: {
                                    enabled: false,
                                } --}}
                            },
                            
                            scales: {
                                x: {
                                    beginAtZero: true,

                                    border: {
                                        dash: [8, 4],
                                    }
                                },

                                y: {
                                    beginAtZero: true,
                                    border: {
                                        dash: [8, 4],
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
    </script>
@endPushOnce