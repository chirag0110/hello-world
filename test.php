@extends('dashboard.master')

@section('scripts')
    <script type="text/javascript" src="/scripts/vue/2.2.6/vue.js"></script>
    <script type="text/javascript" src="/scripts/underscore/1.8.3/underscore.min.js"></script>
    <script>
        var currentMaxDate = new Date({{ $lastThroughputTimestamp }} * 1000);
        var reasons = {!! $reasons or 'null' !!};
        var throughput = {!! $throughput or 'null' !!};
        var lineSetupId = {{ session('clientActiveLine') }};

        var ableToDeleteDowntimes = "{{ session('clientIsAdmin') }}" == "1";
        var splitTotalSeconds = 0;

        var recentDowntimes = true;

        var latestEfficiencyPercent = '0.00%';
        var latestSecondsCount = 0;

        document.addEventListener("DOMContentLoaded", function() {
            addHint(document.getElementById('table-headers'), 'Operator Interface Events', "You can assign events to downtimes by clicking the Assign Reason button next to the downtimes.");
            hideTotalDowntime();
            setupAdminPassword();
            setupGoSetDatesButton();
            setupSelectBoxes();
            setupSplitEvents();
            setupDateTimePicker();
            setupPusher();
            setTimeout(setupGoSetDatesButton, 1000);
            setTimeout(hideDateRange, 1000);
        });

        function setupPusher() {
            var lineSetupChannel = pusher.subscribe('LineSetup.' + lineSetupId);
            lineSetupChannel.bind('Thrive\\Events\\DowntimeChanged', function(data) {
                var downtime = data.downtime;

                if (data.history !== null) {
                    downtime.history = data.history;

                    if (data.throughput !== null) {
                        downtime.history.throughput = data.throughput;
                    }
                }

                if (downtime.EventStop === null) {
                    vStatus.statusDate = new Date(downtime.EventStart);
                    vStatus.statusValue = false;
                    return;
                }

                var downtimeRow = document.querySelector('#DT' + downtime.ID);

                for (var i = 0; i < vm.downtimes.length; i++) {
                    if (vm.downtimes[i].ID.toString() === downtime.ID.toString()) {
                        // Replace the old downtime data with new data
                        vm.downtimes.splice(i, 1, downtime);

                        downtimeRow.classList.add('modifiedRow');
                        setTimeout(function() {
                            downtimeRow.classList.remove('modifiedRow');
                        }, 5000);
                        return;
                    }
                }

                vm.downtimes.unshift(downtime);
                vStatus.statusDate = new Date(downtime.EventStop);
                vStatus.statusValue = true;

                setTimeout(function () {
                    var downtimeRow = document.querySelector('#DT' + downtime.ID);
                    downtimeRow.classList.add('addedRow');
                }, 100);
                setTimeout(function() {
                    var downtimeRow = document.querySelector('#DT' + downtime.ID);
                    downtimeRow.classList.remove('addedRow');
                }, 5100);
            });

            lineSetupChannel.bind('Thrive\\Events\\DowntimeDeleted', function(data) {
                var downtime = data.downtime;

                for (var j = 0; j < vm.downtimes.length; j++) {
                    if (vm.downtimes[j].ID === downtime.ID) {
                        vm.downtimes.splice(j, 1);
                    }
                }
            });

            lineSetupChannel.bind('Thrive\\Events\\LineEffShiftChanged', function(data) {
                vStatus.latestShift = data.shift;
            });
        }

        function setupDateTimePicker() {
            $("#datetime-event").daterangepicker({
                "singleDatePicker": true,
                "showDropdowns": true,
                "locale": { format: 'MM/DD/YYYY h:mm A', applyLabel: 'Apply' },
                "timePicker": true,
                "opens": "center",
                "drops": "up"
            }).on('apply.daterangepicker', function(ev, picker) {
                vm.downtimeDatetime = picker.startDate;
            });
        }

        function setupAdminPassword() {
            if (ableToDeleteDowntimes === true) {
                return true;
            }
            requestAdminPassword("", true);
        }

        function requestAdminPassword(password, async) {
            var loginUrl = "{{ action('Dashboard\AdminController@postLogin') }}";

            $.ajax({
                method: 'POST',
                url: loginUrl,
                data: {
                    password: password
                },
                async: async,
                success: function(response) {
                    ableToDeleteDowntimes = response.success;
                }
            });

            return ableToDeleteDowntimes;
        }

        function setupSelectBoxes() {
            $("select[name=throughput]").select2();
        }

        function secondsToTime(totalSeconds) {
            var hours = Math.floor(totalSeconds / 3600);
            totalSeconds -= hours * 3600;
            var minutes = Math.floor(totalSeconds / 60);
            totalSeconds -= minutes * 60;
            var seconds = Math.round(totalSeconds);

            hours = hours < 10 ? "0" + hours : hours;
            minutes = minutes < 10 ? "0" + minutes : minutes;
            seconds = seconds < 10 ? "0" + seconds : seconds;

            return hours + ":" + minutes + ":" + seconds;
        }

        // time = HH:MM:SS
        function timeToSeconds(time) {
            var parts = time.split(":");
            var hours = parts[0];
            var minutes = parts[1];
            var seconds = parts[2];

            return (hours * 3600) + (minutes * 60) + (seconds * 1);
        }

        function getCurrentThroughput() {
            var throughput = document.getElementById("throughput");

            if (!throughput || throughput.options.length <= 0 || throughput.selectedIndex === -1) {
                return null;
            }

            return throughput.options[throughput.selectedIndex].value;
        }

        function getCurrentThroughputName() {
            var throughput = document.getElementById('throughput');
            var string = throughput.options[throughput.selectedIndex].innerText;
            return string.substring(0, string.indexOf('|') - 1);
        }

        function getSelectedDowntimes() {
            return vm.checkedDowntimeIds;
        }

        function deleteSelectedDowntimes() {
            SAPrompt("Administrative password", "You must enter the correct administrative password to be able to delete downtimes:", "Write the correct administrative password", function (password) {

                if (password === false)
                    return false;

                var correct = requestAdminPassword(password, false);

                if (correct !== true) {
                    SAAlert("Password entered was incorrect", "Please try again.", "error");

                    return false;
                }

                var url = "{{ action('Api\OperatorInterfaceController@destroy') }}";
                var ids = getSelectedDowntimes();
                var downtimeIds = ids.join(',');

                if (ids.length < 1) {
                    SAAlert('Downtime to delete', 'You must select at least one downtime to delete');
                    return false;
                }

                SAConfirm("Are you sure?", 'Are you sure you want to delete Downtime(s) #' + downtimeIds + '?', "Yes!", function (confirm) {
                    if (!confirm)
                        return false;

                    var request = new XMLHttpRequest();
                    request.open("POST", url, true);
                    request.onreadystatechange = function () {
                        if (request.readyState !== 4)
                            return;

                        if (request.status !== 200) {
                            SAAlert("Delete Failed", "Please contact support if this keeps happening!", "error");
                            return false;
                        }

                        vm.removeDowntimes(ids);
                        vm.checkedDowntimeIds = [];
                    };

                    request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
                    request.send("ID=" + encodeURIComponent(downtimeIds));
                });
            });
        }

        function mergeSelectedDowntimes() {
            var url = "{{ action('Api\OperatorInterfaceController@merge') }}";
            var ids = getSelectedDowntimes();
            var downtimeIds = ids.join(',');

            if (ids.length < 2) {
                SAAlert('Downtimes', 'You must select at least two downtimes to be able to merge them together', "warning");
                return;
            }

            SAConfirm("Are you sure?", 'Are you sure you want to merge Downtimes #' + downtimeIds + ' into Downtime #' + ids[0] +  '?', "Yes!", function (confirm) {
                if (!confirm) {
                    return false;
                }

                var request = new XMLHttpRequest();
                request.open("POST", url, true);
                request.onreadystatechange = function () {
                    if (request.readyState !== 4) return;

                    if (request.status !== 200) {
                        SAAlert("Merge Failed", "Please contact support if this keeps happening!", "error");
                    }

                    vm.checkedDowntimeIds = [];
                };

                request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
                request.send("ID=" + encodeURIComponent(downtimeIds));
            });

        }

        function setupSplitEvents() {
            var first = document.getElementById("event-split-first");
            var last = document.getElementById("event-split-last");

            first.addEventListener("change", function() {
                var seconds = timeToSeconds(first.value);
                if (seconds > splitTotalSeconds) {
                    SAAlert("Warning", "You can't increase the amount of the total downtime.", "warning");
                    first.value = secondsToTime(splitTotalSeconds);
                    last.value = secondsToTime(0);
                    return false;
                }
                last.value = secondsToTime(splitTotalSeconds - seconds);
                return true;
            }, false);
        }

        function splitSelectedDowntimes() {
            if (getSelectedDowntimes().length !== 1) {
                SAAlert("Downtimes Selected", 'You must select one single downtime to be able to split it');
                return;
            }

            vm.selectedDowntimeId = vm.checkedDowntimeIds[0];
            var downtime = vm.selectedDowntime;

            splitTotalSeconds = Math.round(downtime.Minutes * 60);

            var first = document.getElementById("event-split-first");
            var last = document.getElementById("event-split-last");
            var splitDowntimeId = document.getElementById("event-split-downtime-id");

            first.value = secondsToTime(splitTotalSeconds);
            last.value = secondsToTime(0);
            splitDowntimeId.value = downtime.ID;

            var box = document.getElementById("split-box");
            box.style.display = "block";
        }

        function saveSplitDowntime() {
            var url = "{{ action('Api\OperatorInterfaceController@split') }}";

            var last = document.getElementById("event-split-last");
            var seconds = timeToSeconds(last.value);

            var downtimeId = document.getElementById("event-split-downtime-id").value;

            var request = new XMLHttpRequest();
            request.open("POST", url, true);
            request.onreadystatechange = function () {
                if (request.readyState !== 4) return;

                if (request.status !== 200) {
                    SAAlert("Save Failed", "Please contact support if this keeps happening!", "error");
                    return;
                }

                var box = document.getElementById("split-box");
                box.style.display = "none";
                vm.selectedDowntime.Comment = "";

                vm.checkedDowntimeIds = [];
            };
            request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            request.send("ID=" + encodeURIComponent(downtimeId) +
                    "&splitEventSeconds=" + encodeURIComponent(seconds)
            );
        }

        function saveDowntimes(ids, reasonCode) {
            var reasonCodeId = reasonCode.ID;
            var url = "{{ action('Api\OperatorInterfaceController@storeMultiple') }}";

            var request = new XMLHttpRequest();
            request.open("POST", url, true);
            request.onreadystatechange = function () {
                if (request.readyState !== 4) return;

                if (request.status !== 200) {
                    SAAlert("Save Failed", "Please contact support if this keeps happening!", "error");
                    return;
                }

                vm.selectedDowntime.Comment = "";
            };
            request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            request.send("IDs=" + ids.join(",") + "&ReasonCodeID=" + encodeURIComponent(reasonCodeId));
        }

        function saveDowntime(id, eventTime, minutes, reasonCode, comment) {
            var reasonCodeId = reasonCode.ID;
            var throughput = getCurrentThroughput();
            var url = "{{ action('Api\OperatorInterfaceController@store') }}";

            eventTime = moment(eventTime).format('YYYY-MM-DD HH:mm');

            var idComponent = '';
            if (id !== null)
                idComponent = "ID=" + id + "&";

            var commentComponent = '';
            if (comment !== null)
                commentComponent = "&Comment=" + encodeURIComponent(comment);

            var throughputComponent = '';
            if (throughput !== null)
                throughputComponent = "&Throughput=" + encodeURIComponent(throughput);

            var reasonComponent = '';
            if (reasonCodeId !== null)
                reasonComponent = "&ReasonCodeID=" + reasonCodeId;

            var request = new XMLHttpRequest();
            request.open("POST", url, true);
            request.onreadystatechange = function () {
                if (request.readyState !== 4) return;

                if (request.status !== 200) {
                    SAAlert("Save Failed", "Please try again once for the event that started at " + eventTime + " and lasted " + minutes + " minutes. If the save fails again, please contact support so we can resolve the issue.", "error");
                    return;
                }

                if (reasonCode !== null && reasonCode.IsChangeOver !== "0" && throughput && eventTime > currentMaxDate) {
                    vStatus.throughput = {
                        "Name": getCurrentThroughputName()
                    };
                }
            };

            vm.wizardIsActive = false;
            request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            request.send(idComponent +
                    "LineSetupId=" + lineSetupId +
                    "&EventStart=" + encodeURIComponent(eventTime) +
                    "&Minutes=" + encodeURIComponent(minutes) +
                    reasonComponent +
                    commentComponent +
                    throughputComponent
            );
        }

        function setupGoSetDatesButton() {
            $('#dateRangePicker').on('apply.daterangepicker', function () {
                recentDowntimes = false;
            });
        }

        function hideTotalDowntime() {
            document.querySelector("#controls > .wrapper > .right").style.display = 'none';
        }

        function hideDateRange() {
            document.querySelector("#dateRangePicker").value = "";
        }

        function initialize() { {{-- Required because this is called by the parent template --}} }

        function startDownTimer() {
            SAConfirm("Are you sure", 'Are you sure you want to start a new downtime event? The end time and the minutes will be calculated once you assign this new downtime.', "Yes",function(confirmAction) {
                if (!confirmAction) return false;

                var url = "{{ action('Api\OperatorInterfaceController@startTimer') }}";

                var request = new XMLHttpRequest();
                request.open("POST", url, true);
                request.onreadystatechange = function () {
                    if (request.readyState !== 4 || request.status !== 200) return;

                    vm.selectedDowntime.Comment = "";
                };
                request.send();
            });
        }
    </script>
    <script type="text/javascript" src="/scripts/nprogress/0.2.0/nprogress.min.js"></script>
    <script type="text/javascript" src="/scripts/select2/4.0.1/select2.min.js"></script>
    <link rel="stylesheet" type="text/css" href="/styles/nprogress/0.2.0/nprogress.min.css">
    <link rel="stylesheet" type="text/css" href="/styles/select2/4.0.1/select2.min.css">
    <link rel="stylesheet" type="text/css" href="/styles/oi.css">
@stop

@section('content')
    <script type="text/x-template" id="oiNavigationContent">
        <div class="active submenu">
            <span style="color: #fff; margin-right: 15px;">Operator Interface</span>
            <span>
                <button onclick="return deleteSelectedDowntimes();">Delete</button>
                <button onclick="return mergeSelectedDowntimes();">Merge</button>
                <button onclick="return splitSelectedDowntimes();">Split</button>
                <button onclick="vm.createNewDowntime();">New</button>
            </span>
            <span style="margin-right: 15px;" v-if="latestThroughput" :title="'SKU: ' + latestThroughput.Name">
                @{{ latestThroughput.Name.length < 15 ? latestThroughput.Name : (latestThroughput.Name.substring(0,13)+'...') }}
            </span>
            <span style="margin-right: 15px;" v-else>No Throughputs</span>
            <span id="line-status" :class="{ redStatus: statusValue === false, greenStatus: statusValue }" :title="statusData.title">
                <template v-if="statusData.shiftActive">
                    <template v-if="latestShift.Efficiency">@{{ (latestShift.Efficiency * 100).toFixed(2) }}%</template>
                    <template v-else>??%</template>
                    <template v-if="statusDate">@{{ hours | timeComponent }}:@{{ minutes | timeComponent }}:@{{ seconds | timeComponent }}</template>
                    <template v-else>[Loading]</template>
                </template>
                <template v-else>No Shift Scheduled</template>
            </span>
        </div>
    </script>

    <div id="oi-platform">
        <div class="full" id="progress-bar" style="height: 5px;" v-show="!wizardIsActive"></div>
        <div class="chart full" v-show="wizardIsActive">
            <div class="wizard clearfix">
                <h3 v-if="selectedDowntime.ID === null">Creating New Downtime</h3>
                <h3 v-else>Modifying Downtime #@{{ selectedDowntime.ID }}</h3>
                <div class="steps clearfix">
                    <ul>
                        <li class="done" @click="wizardIsActive = false;"><a>Event List</a></li>
                        <li :class="{ current: datetimeIsActive, done: !datetimeIsActive }"><a @click="datetimeIsActive = true; commentIsActive = false;">Date &amp; Time</a></li>
                        <li :class="{ current: !datetimeIsActive && !commentIsActive && selectedLevel1 === null, done: datetimeIsActive || commentIsActive || selectedLevel1 !== null }">
                            <a v-if="selectedLevel1 !== null" @click="selectedLevel1 = null; selectedLevel2 = null; selectedLevel3 = null; datetimeIsActive = false; commentIsActive = false;">@{{ selectedLevel1 }}</a>
                            <a v-else @click="datetimeIsActive = false; commentIsActive = false;">1st Level</a>
                        </li>
                        <li :class="{ current: selectedLevel1 !== null && selectedLevel2 === null && !noMoreReasonCodeLevels, disabled: selectedLevel1 === null || noMoreReasonCodeLevels, done: datetimeIsActive || commentIsActive || selectedLevel2 !== null  }">
                            <a v-if="selectedLevel2 !== null" @click="selectedLevel2 = null; selectedLevel3 = null; datetimeIsActive = false; commentIsActive = false;">@{{ selectedLevel2 }}</a>
                            <a v-else>2nd Level</a>
                        </li>
                        <li :class="{ current: selectedLevel2 !== null && selectedLevel3 === null && !noMoreReasonCodeLevels, disabled: selectedLevel2 === null || noMoreReasonCodeLevels, done: datetimeIsActive || commentIsActive || selectedLevel3 !== null }">
                            <a v-if="selectedLevel3 !== null" @click="selectedLevel3 = null;  datetimeIsActive = false; commentIsActive = false;">@{{ selectedLevel3 }}</a>
                            <a v-else>3rd Level</a>
                        </li>
                        <li class="last" :class="{ disabled: selectedReasonCode === null, done: selectedReasonCode !== null && !noMoreReasonCodeLevels, current: commentIsActive || noMoreReasonCodeLevels }">
                            <a @click="enableCommenting()">Comment</a>
                        </li>
                    </ul>
                </div>
                <div style="text-align: right;" v-show="datetimeIsActive">
                    <button style="font-size: 30px; margin-bottom: 25px;" onclick="return startDownTimer();">Start Timer</button>
                    <table>
                        <tbody>
                        <tr>
                            <td style="font-size: 30px;"><label for="date-manual">Start Date &amp; Time</label></td>
                            <td colspan="2">
                                <input id="datetime-event" class="datetime-picker" type="text" style="font-size: 30px;" v-model="downtimeDatetime">
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size: 30px;"><label for="event-minutes">Minutes</label></td>
                            <td><input id="event-minutes" type="number" min="0.01" step="0.01" value="5" style="font-size: 30px;" v-model="selectedDowntime.Minutes"></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="content levels clearfix" v-show="selectedLevel1 === null && !commentIsActive && !datetimeIsActive">
                    <ul>
                        <li v-for="level1 in uniqueLevel1s"><label><input name="level1" type="radio" v-model="selectedLevel1" :value="level1"> @{{ level1 }}</label></li>
                    </ul>
                </div>
                <div class="content levels clearfix" v-show="selectedLevel2 === null && !commentIsActive && !datetimeIsActive">
                    <ul>
                        <li v-for="level2 in uniqueLevel2s"><label><input name="level2" type="radio" v-model="selectedLevel2" :value="level2"> @{{ level2 }}</label></li>
                    </ul>
                </div>
                <div class="content levels clearfix" v-show="selectedLevel3 === null && !commentIsActive && !datetimeIsActive">
                    <ul>
                        <li v-for="level3 in uniqueLevel3s"><label><input name="level3" type="radio" v-model="selectedLevel3" :value="level3"> @{{ level3 }}</label></li>
                    </ul>
                </div>
                <div class="content levels clearfix" v-show="commentIsActive || noMoreReasonCodeLevels">
                    <div style="font-size: 20px; display: none;" v-show="selectedReasonCode !== null && (selectedReasonCode.IsChangeOver == '1' || selectedReasonCode.IsChangeOver === true)">
                        SKU:
                        <select name="throughput" id="throughput" style="min-width: 200px;">
                            @forelse ($throughputs as $throughput)
                                <option value="{{ $throughput->Id }}">{{ $throughput->Name }} | {{ $throughput->Description }}</option>
                            @empty
                                <!-- No $throughputs passed in -->
                            @endforelse
                        </select>
                    </div>
                    <h3>Operator Comments:</h3>
                    <textarea id="event-comment" cols="100" rows="10" placeholder="Operator puts in comment here." v-model.trim="selectedDowntime.Comment"></textarea>
                    <div v-if="requireComment" style="color: red;">*Comments are required for this reason code !!!</div>
                </div>
                <div class="actions clearfix">
                    <ul>
                        <li v-if="commentIsActive || noMoreReasonCodeLevels"><a :class="{ disabled: requireComment }" @click="startSavingDowntimes()" href="javascript:void(0);">Save</a></li>
                        <li><a href="javascript:void(0);" @click="wizardIsActive = false;">Cancel</a></li>
                        <li v-if="datetimeIsActive"><a href="javascript:void(0);" @click="datetimeIsActive = false;">Next</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="chart full" id="split-box">
            <div class="left">
                <input id="event-split-downtime-id" type="hidden">
                <div>First Event (HH::MM::SS)</div>
                <div><input id="event-split-first" value="00:00:00"></div>
            </div>
            <div class="middle"><button>=</button></div>
            <div class="right">
                <div>Remaining Downtime</div>
                <div><input id="event-split-last" readonly="readonly" style="background-color: #ccc;" value="00:00:00"></div>
            </div>
            <div style="margin-top: 25px;"><button onclick="saveSplitDowntime();">Split</button></div>
        </div>
        <div class="chart full" id="event-list" v-show="!wizardIsActive">
            <div id="table-headers">
                <template v-if="downtimes.length > 0">
                    <div class="table-header-checkbox"></div>
                    <div class="table-header-event">Event</div>
                    <div class="table-header-minutes">Minutes</div>
                    <div class="table-header-reasons">Reason</div>
                </template>
                <div v-if="downtimes.length == 0 && checked">No Downtime Events Found</div>
                <div v-if="downtimes.length == 0 && !checked">Loading Downtime Events...</div>
            </div>
            <div class="holder">
                <table id="table-details">
                    <tr v-for="downtime in downtimes" :id="'DT' + downtime.ID" :title="'ID: ' + downtime.ID + ' | ' + (downtime.IsCreatedByAcromag == '1' ? 'System' : 'User') + ' Generated'">
                        <td><input type="checkbox" class="downtime-checkbox" :value="downtime.ID" v-model="checkedDowntimeIds"></td>
                        <td>@{{ downtime.EventStart | momentTz('MM/DD/YYYY hh:mm A') }}</td>
                        <td><span :title="downtime.Minutes + ' minutes'">@{{ (downtime.Minutes * 60) | secondsToTime }}</span></td>
                        <td class="reasonCell">
                            <span class="reasonCodeDisplay">
                                <button v-if="downtime.ReasonCode == null" class="assign-reason-code" @click="assignDowntime(downtime.ID)" :data-id="downtime.ID">Assign Reason</button>
                                <a v-else href="javascript:void(0)" @click="assignDowntime(downtime.ID)">@{{ downtime.ReasonCode }}</a>
                                <span v-if="downtime.history && downtime.history.throughput">[@{{ downtime.history.throughput.Name }}]</span>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <div v-show="downtimes.length > 0 && !wizardIsActive || datetimeIsActive && wizardIsActive" class="chart full" style="min-height: 50px;">
            <select v-model="selectedTimeZone">
                <option value="America/Los_Angeles">Pacific Standard Time (PST)</option>
                <option value="America/Chicago">Central Standard Time (CST)</option>
                <option value="America/New_York">Eastern Standard Time (EST)</option>
                <option value="Australia/Sydney">Australian Eastern Daylight Time (AEDT)</option>
            </select>
        </div>
    </div>

    <script type="text/javascript">
        Vue.filter('moment', function (value, format) {
            return moment(value).format(format);
        });

        Vue.filter('momentTz', function (value, format) {
            return moment.tz(value, serverTimeZone).tz(vm.selectedTimeZone).format(format);
        });

        Vue.filter('secondsToTime', function (value) {
            return secondsToTime(value);
        });

        Vue.filter('timeComponent', function (value) {
            if (value < 0)
                value = "0";

            if (value < 10)
               value = "0" + value;

            return value;
        });

        var vm = new Vue({
            el: '#oi-platform',
            data: {
                downtimes: [],
                checkedDowntimeIds: [],
                reasons: [],
                selectedTimeZone: clientTimeZone,
                wizardIsActive: false,
                commentIsActive: false,
                datetimeIsActive: false,
                checked: false,
                recent: true,
                selectedDowntimeId: null,
                table: document.querySelector('#table-details'),
                selectedLevel1: null,
                selectedLevel2: null,
                selectedLevel3: null,
                newDowntime: {
                    ID: null,
                    EventStart: new Date(),
                    Minutes: 5,
                    IsCreatedByAcromag: false,
                    IsUniversalTime: true,
                    Comment: null,
                    LineSetupId: lineSetupId
                }
            },
            methods: {
                load: function() {
                    var state = this;

                    state.reasons = reasons;

                    NProgress.configure({ parent: '#progress-bar' });
                    NProgress.start();

                    var url = "{{ action('Api\OperatorInterfaceController@index') }}";
                    // var lastDate = "{{ session('clientDateEnd') }}"; // unused for now

                    if (state.recent) {
                        url += "?recent=true";
                    }

                    var request = new XMLHttpRequest();
                    request.open("GET", url, true);

                    request.onreadystatechange = function() {
                        if (request.readyState !== 4 || request.status !== 200) return;

                        state.downtimes = JSON.parse(request.responseText);
                        state.checked = true;
                        NProgress.done();
                    };
                    request.send();
                },

                assignDowntime: function(id) {
                    this.selectedDowntimeId = id;

                    this.selectedLevel1 = null;
                    this.selectedLevel2 = null;
                    this.selectedLevel3 = null;

                    if (this.selectedDowntime.ReasonCodeID !== null) {
                        for (var i = 0; i < reasons.length; i++)
                            if (reasons[i].ID === this.selectedDowntime.ReasonCodeID) {
                                this.selectedLevel1 = reasons[i].Level1;
                                this.selectedLevel2 = reasons[i].Level2;
                                this.selectedLevel3 = reasons[i].Level3;
                            }
                    }

                    this.datetimeIsActive = false;
                    this.wizardIsActive = true;
                },

                greet: function(id) {
                    if (this.selectedDowntime.ReasonCodeID !== null) {
                        for (var i = 0; i < reasons.length; i++)
                            if (reasons[i].ID === this.selectedDowntime.ReasonCodeID) {
                                this.selectedLevel1 = reasons[i].Level1;
                                this.selectedLevel2 = reasons[i].Level2;
                                this.selectedLevel3 = reasons[i].Level3;
                            }
                    }
                },

                greet: function (event) {
                    // `this` inside methods point to the Vue instance
                    alert('Hello ' + this.name + '!')
                    // `event` is the native DOM event
                    alert(event.target.tagName)
                },

                enableCommenting: function() {
                    if (this.selectedReasonCode !== null) {
                        this.datetimeIsActive = false;
                        this.commentIsActive = true;
                    }
                },

                createNewDowntime: function() {
                    vm.selectedLevel1 = null;
                    vm.selectedLevel2 = null;
                    vm.selectedLevel3 = null;
                    vm.selectedDowntimeId = null;
                    vm.datetimeIsActive = true;
                    vm.wizardIsActive = true;
                },

                removeDowntimes: function(downtimeIds) {
                    for (var i = 0; i < downtimeIds.length; i++)
                        for (var j = 0; j < this.downtimes.length; j++)
                            if (this.downtimes[j].ID === downtimeIds[i])
                                this.downtimes.splice(j, 1);
                },

                startSavingDowntimes: function() {
                    var downtimeIds = this.checkedDowntimeIds;
                    var reasonCode = null;

                    if (downtimeIds.length > 0) {
                        SAConfirm("Are you sure?", "Are you sure you want to save your changes to these " + downtimeIds.length + " downtime events?", "Yes!",function(confirm) {
                            if (confirm) {
                                reasonCode = vm.selectedReasonCode;
                                saveDowntimes(downtimeIds, reasonCode);
                            }

                        });

                        return false;
                    }

                    if ( this.requireComment )
                        return false;

                    var vue = this;
                    SAConfirm("Are you sure?", "Are you sure you want to save this single event?", "Yes!", function(confirm) {
                        if (!confirm) {
                            return false;
                        }

                        saveDowntime(
                            vue.selectedDowntime.ID,
                            vue.selectedDowntime.EventStart,
                            vue.selectedDowntime.Minutes,
                            vue.selectedReasonCode,
                            vue.selectedDowntime.Comment
                        );
                    });

                    return false;
                }
            },
            computed: {
                selectedReasonCode: function() {
                    for (var i = 0; i < reasons.length; i++)
                        if (reasons[i].Level1 === this.selectedLevel1 && reasons[i].Level2 === this.selectedLevel2 && reasons[i].Level3 === this.selectedLevel3)
                            return reasons[i];

                    return null;
                },
                selectedDowntime: {
                    get: function () {
                        for (var i = 0; i < this.downtimes.length; i++)
                            if (this.downtimes[i].ID === this.selectedDowntimeId)
                                return this.downtimes[i];

                        return this.newDowntime;
                    },
                    set: function(downtime) {
                        if (downtime === null)
                            return;

                        for (var i = 0; i < this.downtimes.length; i++)
                            if (this.downtimes[i].ID === downtime.ID)
                                this.downtimes[i] = downtime;

                        this.downtimeDatetime = downtime.EventStart;
                    }
                },
                downtimeDatetime: {
                    get: function () {
                        var formattedTime = moment.tz(this.selectedDowntime.EventStart, serverTimeZone).tz(this.selectedTimeZone).format('MM/DD/YYYY hh:mm A');

                        try {
                            $('#datetime-event').data('daterangepicker').setStartDate(formattedTime);
                        } catch (error) { }

                        return formattedTime;
                    },
                    set: function (value) {
                        moment.tz(value, this.selectedTimeZone).tz(serverTimeZone)
                        this.selectedDowntime.EventStart = value;
                    }
                },
                latestThroughput: function() {
                    if (this.latestHistory !== null && typeof(this.latestHistory) !== "undefined") {
                        return this.latestHistory.throughput;
                    }

                    return null;
                },
                latestHistory: function() {
                    var latestHistory = null;
                    for (var i = 0; i < this.downtimes.length; i++) {
                        if (this.downtimes[i].history !== null && typeof(this.downtimes[i].history) !== "undefined") {
                            if (latestHistory === null || moment(this.downtimes[i].history.Date) > moment(latestHistory.Date)) {
                                latestHistory = this.downtimes[i].history;
                            }
                        }
                    }

                    return latestHistory;
                },
                uniqueLevel1s: function() {
                    var levels = [];

                    for (var i = 0; i < reasons.length; i++)
                        levels.push(reasons[i].Level1);

                    return _.uniq(levels);
                },
                uniqueLevel2s: function() {
                    var levels = [];

                    for (var i = 0; i < reasons.length; i++)
                        if (reasons[i].Level1 === this.selectedLevel1 && reasons[i].Level2 !== null)
                            levels.push(reasons[i].Level2);

                    return _.uniq(levels);
                },
                uniqueLevel3s: function() {
                    var levels = [];

                    for (var i = 0; i < reasons.length; i++)
                        if (reasons[i].Level1 === this.selectedLevel1 && reasons[i].Level2 === this.selectedLevel2 && reasons[i].Level3 !== null)
                            levels.push(reasons[i].Level3);

                    return _.uniq(levels);
                },
                noMoreReasonCodeLevels: function() {
                    return this.selectedReasonCode !== null
                        && !this.datetimeIsActive
                        && (
                            this.selectedLevel3 !== null
                            || (this.selectedLevel2 !== null && this.uniqueLevel3s.length === 0)
                            || (this.selectedLevel1 !== null && this.uniqueLevel2s.length === 0)
                        );
                },
                requireComment: function() {
                    if (this.selectedReasonCode !== null && this.selectedReasonCode.NeedComment === "1" && (this.selectedDowntime.Comment === null || this.selectedDowntime.Comment.length < 2)) {
                        return true;
                    }
                    return false;
                }
            },
            created: function() {
                this.load();
            }
        });

        handleDatesChange = function() {
            vm.$data.recent = false;
            vm.load();
        };

        var vStatus = new Vue({
            el: '.active.submenu',
            template: '#oiNavigationContent',
            data: {
                now: new Date(),
                latestShift: {},
                status: {},
                statusDate: null,
                statusValue: null,
                throughput: vm.latestThroughput
            },
            created: function() {
                var that = this;

                setInterval(function () {
                    that.now = new Date();
                }, 1000);

                var url = "{{ action('Api\OperatorInterfaceController@linestatus') }}";
                var request = new XMLHttpRequest();
                request.open("GET", url, true);
                request.onreadystatechange = function () {
                    if (request.readyState !== 4 || request.status !== 200) return;

                    var result = JSON.parse(request.responseText);
                    that.status = result.status;
                    that.latestShift = result.lastShift;
                    that.statusDate = new Date(result.status.EventTime);
                    that.statusValue = result.status.Status == 0 ? false : result.status.Status;
                };
                request.send();
            },

            computed: {
                seconds: function() {
                    return Math.round(((this.now - this.statusDate) / 1000) % 60);
                },
                minutes: function() {
                    return Math.round(Math.floor((this.now - this.statusDate -  this.hours * 3600 * 1000) / 1000 / 60));
                },
                hours: function() {
                    return Math.round(Math.floor((this.now - this.statusDate) / 1000 / 60 / 60));
                },
                latestThroughput: function() {
                    if (typeof(vm) === "undefined" || vm.latestThroughput === null) {
                        console.log('woah buddy!');
                        return throughput;
                    }

                    return vm.latestThroughput;
                },
                statusData: function() {
                    var title = '';
                    var shiftActive = false;
                    if (this.latestShift.StartDate) {
                        var startTime = moment.tz(this.latestShift.StartDate, serverTimeZone).tz(vm.selectedTimeZone);
                        var endTime = moment.tz(this.latestShift.EndDate, serverTimeZone).tz(vm.selectedTimeZone);
                        var currentTime = moment(this.now);
                        if (startTime <= currentTime && currentTime <= endTime) {
                            title = this.latestShift.Name + ' : ' + startTime.format("M/D/YY h:mm a") + ' - ' + endTime.format("M/D/YY h:mm a");
                            shiftActive = true;
                        }
                    }
                    return {title: title, shiftActive: shiftActive};
                }
            },

            updateLineStatus: function(lineStatus) {
                var status = document.querySelector('.line-status');
                status.style.backgroundColor = lineStatus.status ? '#73BF43' : '#C53131';
                latestSecondsCount = lineStatus.seconds;
                latestEfficiencyPercent = "No Shift";
                if (lineStatus.lastShift) {
                    var startTime = moment.tz(lineStatus.lastShift.StartDate, serverTimeZone).tz(vm.selectedTimeZone);
                    var endTime = moment.tz(lineStatus.lastShift.EndDate, serverTimeZone).tz(vm.selectedTimeZone);
                    var currentTime = moment.tz(moment(), clientTimeZone);
                    if (startTime <= currentTime && currentTime <= endTime) {
                        var title = lineStatus.lastShift.Name + ' : ' + startTime.format("M/D/YY h:mm a") + ' - ' + endTime.format("M/D/YY h:mm a");
                        latestEfficiencyPercent = '<span title="'+ title +'">' + lineStatus.percent + '</span>';
                    }
                }
            }

        });

    </script>
@stop
