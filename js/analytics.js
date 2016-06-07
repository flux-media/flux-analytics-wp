function getGendersSeries(ageSeries) {
    var result = {name: '성별', data: []}, sum = 0;
    for (var i = 0, seriesLength = ageSeries.length; i < seriesLength; i++) {
        var series = ageSeries[i],
            seriesData = series.data;
        for (var j = 0, length = seriesData.length; j < length; j++) {
            sum += seriesData[j];
        }
        sum = Math.abs(sum);
        result.data.push({
            name: ageSeries[i].name,
            y: sum
        });
        sum = 0;
    }
    return [result];
}

function getAgesSeries(ageSeries, ageCategories) {
    var result = {name: '연령대', data: []}, sum = 0;
    for (var i = 0, categoriesLength = ageCategories.length;
         i < categoriesLength; i++) {
        var female = ageSeries[0].data[i] || 0,
            male = ageSeries[1].data[i] || 0;
        sum = Math.abs(female) + Math.abs(male);
        result.data.push({
            name: ageCategories[i],
            y: sum
        });
        sum = 0;
    }
    return [result];
}

(function ($) {
    $(document).ready(function () {
        var gendersSeries = getGendersSeries(ageSeries),
            agesSeries = getAgesSeries(ageSeries, ageCategories);

        for (var i = 0, length = ageSeries.length; i < length; i++) {
            ageSeries[i].data = ageSeries[i].data.map(function (value) {
                return value / totalPageviews * 100;
            });
        }

        $('#views').highcharts({
            title: {
                text: '구글 애널리틱스',
                x: -20 //center
            },
            subtitle: {
                text: 'PV, UV 누적'
            },
            xAxis: {
                categories: lineChartCategories
            },
            yAxis: [{
                labels: {
                    style: {
                        color: Highcharts.getOptions().colors[0]
                    }
                },
                title: {
                    text: '카운트',
                    style: {
                        color: Highcharts.getOptions().colors[0]
                    }
                }
            }, {
                labels: {
                    style: {
                        color: Highcharts.getOptions().colors[2]
                    }
                },
                title: {
                    text: '초',
                    style: {
                        color: Highcharts.getOptions().colors[2]
                    }
                },
                opposite: true
            }],
            legend: {
                layout: 'vertical',
                align: 'right',
                verticalAlign: 'middle',
                borderWidth: 0
            },
            series: lineChartSeries
        });

        $('#ages-by-genders').highcharts({
            chart: {
                type: 'bar'
            },
            title: {
                text: '성별, 연령대'
            },
            subtitle: {
                text: 'PV 기준'
            },
            xAxis: [{
                categories: ageCategories,
                reversed: false,
                labels: {
                    step: 1
                }
            }, { // mirror axis on right side
                opposite: true,
                reversed: false,
                categories: ageCategories,
                linkedTo: 0,
                labels: {
                    step: 1
                }
            }],
            yAxis: {
                title: {
                    text: null
                },
                labels: {
                    formatter: function () {
                        return Math.abs(this.value) + '%';
                    }
                }
            },

            plotOptions: {
                series: {
                    stacking: 'normal'
                }
            },

            tooltip: {
                formatter: function () {
                    return this.point.y + '% ( ' +
                        parseInt(Math.abs(this.point.y) * totalPageviews / 100, 10) +
                        ')';
                }
            },

            series: ageSeries
        });

        $('#scroll-depth').highcharts({
            title: {
                text: '스크롤 뎁스'
            },
            subtitle: {
                text: 'PV 기준'
            },
            chart: {
                type: 'pie'
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true
                    },
                    showInLegend: true
                }
            },
            tooltip: {
                formatter: function () {
                    return (this.point.y / totalPageviews * 100).toFixed(2) + '%';
                }
            },
            series: scrollDepthSeries
        });

        $('#genders').highcharts({
            title: {
                text: '성별'
            },
            subtitle: {
                text: 'PV 기준'
            },
            chart: {
                type: 'pie'
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true
                    },
                    showInLegend: true
                }
            },
            tooltip: {
                formatter: function () {
                    return (this.point.y / totalPageviews * 100).toFixed(2) + '%';
                }
            },
            series: gendersSeries
        });

        $('#ages').highcharts({
            title: {
                text: '연령대'
            },
            subtitle: {
                text: 'PV 기준'
            },
            chart: {
                type: 'pie'
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true
                    },
                    showInLegend: true
                }
            },
            tooltip: {
                formatter: function () {
                    return (this.point.y / totalPageviews * 100).toFixed(2) + '%';
                }
            },
            series: agesSeries
        });
    });
})(jQuery);
