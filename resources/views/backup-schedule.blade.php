@extends('layouts.librenmsv1')

@section('content')
    @component($plugin->name . '::layouts.panel', ['plugin' => $plugin])
        <div style="font-size: 1.1em; font-weight: 600; margin-bottom: .5em; color:#333">Backup Schedule</div>
        @include($plugin->name . '::layouts.flash-messages')

        @if ($errors->any())
            <ul class="text-danger" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <p>
            @if ($nextRun)
                Next run <strong>{{ $nextRun->format('D, M j, Y \a\t g:i A T') }}</strong>
            @else
                Scheduled backups are paused.
            @endif
        </p>
        <p>Set the recipient for scheduled run summaries under <a href="{{ route($plugin->name . '.settings') }}#backup_report_email">Backup
                Report Email</a>.</p>

        <form method="POST" action="{{ route($plugin->name . '.backups.schedule.update') }}">
            @csrf

            <div class="checkbox">
                <label>
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $schedule->enabled))>
                    Enable scheduled backups for all enrolled devices
                </label>
            </div>

            <fieldset class="form-group" style="margin-top: 1em">
                <legend style="font-size: 1.1em; font-weight: 600; margin-bottom: .5em; border: none;">Days to Run</legend>
                @foreach ($weekdays as $day => $label)
                    <label class="checkbox-inline">
                        <input type="checkbox" name="days[]" value="{{ $day }}" @checked(in_array($day, (array) old('days', $schedule->days)))>
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>

            @php($previousTime = old('time', $schedule->time))
            <div class="form-group">
                <label for="backup-time">Time</label>
                <input type="time" class="form-control" id="backup-time" name="time"
                    value="{{ is_string($previousTime) ? $previousTime : $schedule->time }}" required>
            </div>

            <div class="form-group">
                <label for="backup-timezone">Time Zone</label>
                <select class="form-control" id="backup-timezone" name="timezone" required>
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $schedule->timezone) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Save Schedule</button>
        </form>
    @endcomponent
@endsection
