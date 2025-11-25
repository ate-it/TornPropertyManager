using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Threading.Tasks;
using System.Windows.Input;
using Avalonia.Controls;
using TornPropertyManager.Models;

namespace TornPropertyManager;

public partial class MainWindow : Window, INotifyPropertyChanged
{
    private readonly SettingsService _settingsService;
    private readonly TornApiService _apiService;

    public ObservableCollection<TornPropertySummary> Properties { get; } =
        new ObservableCollection<TornPropertySummary>();

    public ICommand ResetApiKeyCommand { get; }

    private string _statusMessage = "Loading properties...";
    public string StatusMessage
    {
        get => _statusMessage;
        set
        {
            if (_statusMessage != value)
            {
                _statusMessage = value;
                OnPropertyChanged();
            }
        }
    }

    // Required by Avalonia XAML
    public MainWindow()
        : this(new SettingsService(), new TornApiService())
    {
    }

    public MainWindow(SettingsService settingsService, TornApiService apiService)
    {
        InitializeComponent();
        _settingsService = settingsService;
        _apiService = apiService;

        ResetApiKeyCommand = new RelayCommand(ResetApiKey);

        DataContext = this;

        this.Opened += async (_, _) => await LoadPropertiesAsync();
    }

    private async Task LoadPropertiesAsync()
    {
        StatusMessage = "Loading properties from Torn API...";

        var settings = _settingsService.Load();
        var apiKey = settings.ApiKey;

        if (string.IsNullOrWhiteSpace(apiKey))
        {
            StatusMessage = "No API key saved.";
            return;
        }

        var props = await _apiService.GetPropertiesAsync(apiKey);

        Properties.Clear();
        foreach (var p in props)
        {
            Properties.Add(p);
        }

        StatusMessage = $"Loaded {props.Count} properties.";
    }

    private void ResetApiKey()
    {
        var settings = _settingsService.Load();
        settings.ApiKey = null;
        _settingsService.Save(settings);

        var apiWindow = new ApiKeyWindow(_settingsService, _apiService);
        apiWindow.Show();

        Close();
    }

    public new event PropertyChangedEventHandler? PropertyChanged;

    private void OnPropertyChanged([CallerMemberName] string? name = null)
        => PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(name));
}
