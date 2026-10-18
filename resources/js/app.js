import { createPopper } from '@popperjs/core';
import './bootstrap';
import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';

// flatpickr
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Indonesian } from 'flatpickr/dist/esm/l10n/id.js';
// FullCalendar
import { Calendar } from 'fullcalendar';
// Rich text editor (TipTap dimuat lazy di dalam komponen)
import richEditor from './components/rich-editor';
import managementForm from './components/management-form';
import memberPicker from './components/member-picker';
import slugField from './components/slug-field';
import youtubeField from './components/youtube-field';
import galleryForm from './components/gallery-form';
import locationField from './components/location-field';


window.Alpine = Alpine;
window.createPopper = createPopper;
window.ApexCharts = ApexCharts;
flatpickr.l10ns.id = Indonesian; // dipakai lewat opsi locale: 'id'
window.flatpickr = flatpickr;
window.FullCalendar = Calendar;

Alpine.data('richEditor', richEditor);
Alpine.data('managementForm', managementForm);
Alpine.data('memberPicker', memberPicker);
Alpine.data('slugField', slugField);
Alpine.data('youtubeField', youtubeField);
Alpine.data('galleryForm', galleryForm);
Alpine.data('locationField', locationField);

Alpine.start();

// Initialize components on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // DataTables (dimuat hanya di halaman yang memakainya)
    if (document.querySelector('#membersTable')) {
        import('./components/datatables/members-table').then(module => module.initMembersTable());
    }

    // Map imports
    if (document.querySelector('#mapOne')) {
        import('./components/map').then(module => module.initMap());
    }

    // Chart imports
    if (document.querySelector('#chartOne')) {
        import('./components/chart/chart-1').then(module => module.initChartOne());
    }
    if (document.querySelector('#chartTwo')) {
        import('./components/chart/chart-2').then(module => module.initChartTwo());
    }
    if (document.querySelector('#chartThree')) {
        import('./components/chart/chart-3').then(module => module.initChartThree());
    }
    if (document.querySelector('#chartSix')) {
        import('./components/chart/chart-6').then(module => module.initChartSix());
    }
    if (document.querySelector('#chartEight')) {
        import('./components/chart/chart-8').then(module => module.initChartEight());
    }
    if (document.querySelector('#chartThirteen')) {
        import('./components/chart/chart-13').then(module => module.initChartThirteen());
    }

    // Calendar init
    if (document.querySelector('#calendar')) {
        import('./components/calendar-init').then(module => module.calendarInit());
    }
});
